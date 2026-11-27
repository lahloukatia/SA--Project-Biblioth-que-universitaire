import java.io.*;
import java.math.BigDecimal;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.sql.*;
import java.util.concurrent.*;
import java.util.regex.Pattern;

/**
 * Serveur applicatif (TCP) de la bibliothèque universitaire.
 * Protocole texte : une ligne = COMMANDE|champ|champ ... terminée par '\n'.
 * Configuration par variables d'environnement (voir .env) : DB_HOST, DB_PORT, DB_NAME,
 * DB_USER, DB_PASS (obligatoire, aucune valeur par défaut), PORT_SERVEUR, DELAI_INACTIVITE_S.
 */
public class Serveur {
    static String env(String k, String d) { String v = System.getenv(k); return (v == null || v.isEmpty()) ? d : v; }

    static final int PORT = Integer.parseInt(env("PORT_SERVEUR", "5000"));
    static final int DELAI_INACTIVITE_MS = Integer.parseInt(env("DELAI_INACTIVITE_S", "60")) * 1000;
    static final int LONGUEUR_MAX = 256;            // longueur maximale d'une ligne reçue
    static final int ECHECS_HELLO_MAX = 3;          // identifications ratées avant fermeture
    static final BigDecimal TARIF_JOUR = new BigDecimal("0.20"); // amende par jour de retard
    static final String TROP_LONG = "\u0000TROP_LONG";

    static final String DB_URL =
        "jdbc:postgresql://" + env("DB_HOST", "localhost") + ":"
        + env("DB_PORT", "5432") + "/"
        + env("DB_NAME", "bibliotheque")
        + "?sslmode=require";
    static final String DB_USER = env("DB_USER", "bibli_user");
    static final String DB_PASS = System.getenv("DB_PASS");

    static final Pattern P_ID = Pattern.compile("[0-9]{1,9}");
    static final Pattern P_CODE = Pattern.compile("EX[0-9]{3,10}");

    /** Refus métier : code d'erreur renvoyé au client. */
    static class Refus extends Exception {
        final String code;
        Refus(String code) { super(code); this.code = code; }
    }

    public static void main(String[] args) throws IOException {
        if (DB_PASS == null || DB_PASS.isEmpty()) {
            System.err.println("DB_PASS manquant : lance le serveur avec ./scripts/run_serveur.sh");
            System.exit(1);
        }
        ExecutorService pool = Executors.newFixedThreadPool(20);
        try (ServerSocket ecoute = new ServerSocket(PORT)) {
            System.out.println("Serveur en écoute sur le port " + PORT);
            while (true) {
                Socket s = ecoute.accept();
                pool.submit(() -> new Session(s).run());
            }
        }
    }

    static String champ(String s) { return s.replace("|", " ").replace("\n", " "); }

    /** Une session = un client connecté (un thread, une connexion à la base). */
    static class Session {
        final Socket socket;
        final String client;
        BufferedReader in;
        PrintWriter out;
        Connection conn;
        Integer idBiblio = null;   // identifiant du bibliothécaire une fois identifié
        int echecsHello = 0;

        Session(Socket s) { socket = s; client = s.getRemoteSocketAddress().toString(); }

        void run() {
            System.out.println("[" + client + "] connexion");
            try (Socket s = socket) {
                s.setSoTimeout(DELAI_INACTIVITE_MS);
                in = new BufferedReader(new InputStreamReader(s.getInputStream(), StandardCharsets.UTF_8));
                out = new PrintWriter(new OutputStreamWriter(s.getOutputStream(), StandardCharsets.UTF_8), true);
                try {
                    conn = DriverManager.getConnection(DB_URL, DB_USER, DB_PASS);
                } catch (SQLException e) {
                    System.err.println("[" + client + "] BD indisponible : " + e.getMessage());
                    envoyer("ERR|E99");
                    return;
                }
                try {
                    boucle();
                } catch (SocketTimeoutException e) {
                    // à traiter ICI : le try-with-resources fermerait la socket avant un catch extérieur
                    envoyer("TIMEOUT");
                    System.out.println("[" + client + "] inactivité : fermeture");
                }
            } catch (IOException e) {
                System.out.println("[" + client + "] connexion perdue : " + e.getMessage());
            } finally {
                try { if (conn != null) conn.close(); } catch (SQLException ignore) { }
                System.out.println("[" + client + "] fermée");
            }
        }

        void envoyer(String msg) {
            if (out != null) { out.print(msg + "\n"); out.flush(); }
            System.out.println("[" + client + "] > " + msg);
        }

        /** Lit une ligne de 256 caractères au plus ; TROP_LONG si dépassement ; null si le client a fermé. */
        String lireLigne() throws IOException {
            StringBuilder sb = new StringBuilder();
            boolean tropLong = false;
            int c;
            while ((c = in.read()) != -1) {
                if (c == '\n') return tropLong ? TROP_LONG : sb.toString();
                if (c == '\r') continue;
                if (sb.length() >= LONGUEUR_MAX) tropLong = true; else sb.append((char) c);
            }
            return null;
        }

        void boucle() throws IOException {
            String ligne;
            while ((ligne = lireLigne()) != null) {
                System.out.println("[" + client + "] < "
                        + (ligne.length() > 60 ? ligne.substring(0, 60) + "..." : ligne));
                if (ligne.equals(TROP_LONG)) { envoyer("ERR|E03"); continue; }
                String[] p = ligne.split("\\|", -1);
                String cmd = p[0];
                try {
                    switch (cmd) {
                        case "QUIT":
                            if (p.length != 1) { envoyer("ERR|E03"); break; }
                            envoyer("BYE");
                            return;
                        case "HELLO":
                            if (p.length != 2 || !P_ID.matcher(p[1]).matches()) { envoyer("ERR|E03"); break; }
                            if (!hello(Integer.parseInt(p[1]))) return;
                            break;
                        case "ADH":
                            if (idBiblio == null) { envoyer("ADH_KO|E02"); break; }
                            if (p.length != 2 || !P_ID.matcher(p[1]).matches()) { envoyer("ERR|E03"); break; }
                            adh(Integer.parseInt(p[1]));
                            break;
                        case "PRET":
                            if (idBiblio == null) { envoyer("PRET_KO|E02"); break; }
                            if (p.length != 3 || !P_ID.matcher(p[1]).matches() || !P_CODE.matcher(p[2]).matches()) {
                                envoyer("ERR|E03"); break; }
                            pret(Integer.parseInt(p[1]), p[2]);
                            break;
                        case "RETOUR":
                            if (idBiblio == null) { envoyer("RETOUR_KO|E02"); break; }
                            if (p.length != 2 || !P_CODE.matcher(p[1]).matches()) { envoyer("ERR|E03"); break; }
                            retour(p[1]);
                            break;
                        default:
                            envoyer("ERR|E03");
                    }
                } catch (SQLException e) {
                    System.err.println("[" + client + "] erreur SQL : " + e.getMessage());
                    envoyer("ERR|E99");
                }
            }
        }

        /** Identification du poste (pas une authentification). Renvoie false si la connexion doit être fermée. */
        boolean hello(int id) throws SQLException {
            String sql = "SELECT p.nom, p.prenom FROM bibliothecaire b "
                    + "JOIN personne p ON p.id_personne = b.id_personne WHERE b.id_bibliothecaire = ?";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setInt(1, id);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        idBiblio = id;
                        echecsHello = 0;
                        envoyer("HELLO_OK|" + champ(rs.getString(1)) + "|" + champ(rs.getString(2)));
                        return true;
                    }
                }
            }
            idBiblio = null;
            envoyer("HELLO_KO|E01");
            if (++echecsHello >= ECHECS_HELLO_MAX) {
                System.out.println("[" + client + "] trop d'échecs d'identification");
                return false;
            }
            return true;
        }

        void adh(int id) throws SQLException {
            String sql = "SELECT p.nom, p.prenom, a.type_adherent::text, "
                    + "(SELECT COUNT(*) FROM emprunt e WHERE e.id_adherent = a.id_adherent AND e.date_retour_effective IS NULL), "
                    + "(SELECT COALESCE(SUM(e.montant_amende), 0) FROM emprunt e WHERE e.id_adherent = a.id_adherent AND e.statut_amende = 'impayee') "
                    + "FROM adherent a JOIN personne p ON p.id_personne = a.id_personne WHERE a.id_adherent = ?";
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                ps.setInt(1, id);
                try (ResultSet rs = ps.executeQuery()) {
                    if (!rs.next()) { envoyer("ADH_KO|E10"); return; }
                    envoyer("ADH_OK|" + champ(rs.getString(1)) + "|" + champ(rs.getString(2)) + "|" + rs.getString(3)
                            + "|" + rs.getInt(4) + "|" + rs.getBigDecimal(5).setScale(2));
                }
            }
        }

        /** Un entier renvoyé par une requête "SELECT COUNT(*) ..." à un paramètre ou deux. */
        int compter(String sql, Object... params) throws SQLException {
            try (PreparedStatement ps = conn.prepareStatement(sql)) {
                for (int i = 0; i < params.length; i++) ps.setObject(i + 1, params[i]);
                try (ResultSet rs = ps.executeQuery()) { rs.next(); return rs.getInt(1); }
            }
        }

        /** Enregistre un prêt : INSERT dans emprunt, UPDATE éventuel de reservation. */
        void pret(int idAdh, String code) throws SQLException {
            conn.setAutoCommit(false);
            try {
                // 1. adhérent connu, actif, sans amende impayée
                try (PreparedStatement ps = conn.prepareStatement("SELECT actif_adherent FROM adherent WHERE id_adherent = ?")) {
                    ps.setInt(1, idAdh);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (!rs.next()) throw new Refus("E10");
                        if (!rs.getBoolean(1)) throw new Refus("E11");
                    }
                }
                if (compter("SELECT COUNT(*) FROM emprunt WHERE id_adherent = ? AND statut_amende = 'impayee'", idAdh) > 0)
                    throw new Refus("E12");

                // 2. exemplaire connu et en bon état
                int idEx, idLivre;
                try (PreparedStatement ps = conn.prepareStatement(
                        "SELECT id_exemplaire, id_livre, etat_exemplaire::text FROM exemplaire WHERE code_inventaire = ?")) {
                    ps.setString(1, code);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (!rs.next()) throw new Refus("E13");
                        idEx = rs.getInt(1); idLivre = rs.getInt(2);
                        if (!rs.getString(3).equals("bon")) throw new Refus("E14");
                    }
                }

                // Verrou sur le livre : deux prêts du même livre sont traités l'un après l'autre.
                try (PreparedStatement ps = conn.prepareStatement("SELECT 1 FROM livre WHERE id_livre = ? FOR UPDATE")) {
                    ps.setInt(1, idLivre); ps.executeQuery().close();
                }
                if (compter("SELECT COUNT(*) FROM emprunt WHERE id_exemplaire = ? AND date_retour_effective IS NULL", idEx) > 0)
                    throw new Refus("E14");

                // 3. réservations : un exemplaire réservé par un autre adhérent n'est pas prêtable
                boolean aReservation = compter("SELECT COUNT(*) FROM reservation WHERE id_livre = ? AND id_adherent = ? "
                        + "AND statut_reservation = 'en_attente'", idLivre, idAdh) > 0;
                int autres = compter("SELECT COUNT(*) FROM reservation WHERE id_livre = ? AND id_adherent <> ? "
                        + "AND statut_reservation = 'en_attente'", idLivre, idAdh);
                int disponibles = compter("SELECT COUNT(*) FROM exemplaire x WHERE x.id_livre = ? AND x.etat_exemplaire = 'bon' "
                        + "AND NOT EXISTS (SELECT 1 FROM emprunt e WHERE e.id_exemplaire = x.id_exemplaire "
                        + "AND e.date_retour_effective IS NULL)", idLivre);
                if (!aReservation && autres >= disponibles) throw new Refus("E16");

                // 4. création de l'emprunt (retour prévu = date du jour + 14 jours) ; l'id est généré par la base
                int idEmprunt;
                String retourPrevu;
                String ins = "INSERT INTO emprunt (date_emprunt, date_retour_prevue, montant_amende, statut_amende, "
                        + "id_adherent, id_bibliothecaire, id_exemplaire) "
                        + "VALUES (CURRENT_DATE, CURRENT_DATE + 14, 0, 'aucune', ?, ?, ?) "
                        + "RETURNING id_emprunt, date_retour_prevue";
                try (PreparedStatement ps = conn.prepareStatement(ins)) {
                    ps.setInt(1, idAdh); ps.setInt(2, idBiblio); ps.setInt(3, idEx);
                    try (ResultSet rs = ps.executeQuery()) { rs.next(); idEmprunt = rs.getInt(1); retourPrevu = rs.getString(2); }
                }

                // 5. réservation en attente de cet adhérent sur ce livre : honorée
                int honoree;
                String upd = "UPDATE reservation SET statut_reservation = 'recupere' WHERE id_reservation = "
                        + "(SELECT MIN(id_reservation) FROM reservation WHERE id_adherent = ? AND id_livre = ? "
                        + "AND statut_reservation = 'en_attente')";
                try (PreparedStatement ps = conn.prepareStatement(upd)) {
                    ps.setInt(1, idAdh); ps.setInt(2, idLivre);
                    honoree = ps.executeUpdate() > 0 ? 1 : 0;
                }
                conn.commit();
                envoyer("PRET_OK|" + idEmprunt + "|" + retourPrevu + "|" + honoree);
            } catch (Refus r) {
                conn.rollback();
                envoyer("PRET_KO|" + r.code);
            } catch (SQLException e) {
                conn.rollback();
                if ("23505".equals(e.getSQLState())) envoyer("PRET_KO|E14");   // doublon : exemplaire déjà prêté
                else throw e;
            } finally {
                conn.setAutoCommit(true);
            }
        }

        /** Enregistre un retour : UPDATE de emprunt, amende calculée en cas de retard. */
        void retour(String code) throws SQLException {
            conn.setAutoCommit(false);
            try {
                int idEx, idLivre;
                try (PreparedStatement ps = conn.prepareStatement(
                        "SELECT id_exemplaire, id_livre FROM exemplaire WHERE code_inventaire = ?")) {
                    ps.setString(1, code);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (!rs.next()) throw new Refus("E13");
                        idEx = rs.getInt(1); idLivre = rs.getInt(2);
                    }
                }
                int idEmprunt, retard;
                String sel = "SELECT id_emprunt, (CURRENT_DATE - date_retour_prevue) FROM emprunt "
                        + "WHERE id_exemplaire = ? AND date_retour_effective IS NULL FOR UPDATE";
                try (PreparedStatement ps = conn.prepareStatement(sel)) {
                    ps.setInt(1, idEx);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (!rs.next()) throw new Refus("E15");
                        idEmprunt = rs.getInt(1); retard = rs.getInt(2);
                    }
                }
                int jours = Math.max(0, retard);
                BigDecimal montant = TARIF_JOUR.multiply(BigDecimal.valueOf(jours)).setScale(2);
                if (jours > 0) {
                    String upd = "UPDATE emprunt SET date_retour_effective = CURRENT_DATE, montant_amende = ?, "
                            + "date_emission = CURRENT_DATE, statut_amende = 'impayee' WHERE id_emprunt = ?";
                    try (PreparedStatement ps = conn.prepareStatement(upd)) {
                        ps.setBigDecimal(1, montant); ps.setInt(2, idEmprunt); ps.executeUpdate();
                    }
                } else {
                    try (PreparedStatement ps = conn.prepareStatement(
                            "UPDATE emprunt SET date_retour_effective = CURRENT_DATE WHERE id_emprunt = ?")) {
                        ps.setInt(1, idEmprunt); ps.executeUpdate();
                    }
                }
                int enAttente = compter("SELECT COUNT(*) FROM reservation WHERE id_livre = ? "
                        + "AND statut_reservation = 'en_attente'", idLivre) > 0 ? 1 : 0;
                conn.commit();
                envoyer("RETOUR_OK|" + idEmprunt + "|" + jours + "|" + montant + "|" + enAttente);
            } catch (Refus r) {
                conn.rollback();
                envoyer("RETOUR_KO|" + r.code);
            } catch (SQLException e) {
                conn.rollback();
                throw e;
            } finally {
                conn.setAutoCommit(true);
            }
        }
    }
}
