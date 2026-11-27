#!/usr/bin/env python3
"""Client réseau (poste du comptoir) de la bibliothèque universitaire.

Utilisation : python3 python/client_bibliotheque.py [hote] [port]     (défaut : localhost 5000)
Protocole texte : une ligne = COMMANDE|champ|champ ... (voir le rapport).
"""
import socket
import sys
import time

HOTE = sys.argv[1] if len(sys.argv) > 1 else "localhost"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 5000
DELAI = 10          # secondes d'attente maximale d'une réponse
ESSAIS = 3          # tentatives de (re)connexion
ECHECS_HELLO_MAX = 3  # identiques au serveur : il ferme la connexion après 3 refus

ERREURS = {
    "E01": "identifiant de bibliothécaire inconnu",
    "E02": "poste non identifié (HELLO requis)",
    "E03": "message invalide",
    "E10": "adhérent inconnu",
    "E11": "adhérent inactif (non autorisé à emprunter)",
    "E12": "amende impayée : prêt refusé",
    "E13": "exemplaire inconnu",
    "E14": "exemplaire indisponible (déjà emprunté, abîmé ou perdu)",
    "E15": "aucun emprunt en cours pour cet exemplaire",
    "E16": "exemplaire réservé par un autre adhérent",
    "E99": "erreur interne du serveur (base de données)",
}


class Connexion:
    def __init__(self):
        self.sock = None
        self.f = None
        self.id_biblio = None   # mémorisé pour se ré-identifier après une coupure

    def ouvrir(self):
        for essai in range(1, ESSAIS + 1):
            try:
                self.sock = socket.create_connection((HOTE, PORT), timeout=DELAI)
                self.f = self.sock.makefile("rw", encoding="utf-8", newline="\n")
                return True
            except OSError as e:
                print(f"  connexion impossible ({e}) - essai {essai}/{ESSAIS}")
                time.sleep(2)
        return False

    def fermer(self):
        for obj in (self.f, self.sock):
            try:
                if obj:
                    obj.close()
            except OSError:
                pass
        self.f = self.sock = None

    def _echange(self, msg):
        self.f.write(msg + "\n")
        self.f.flush()
        rep = self.f.readline()
        if rep == "":
            raise ConnectionError("le serveur a fermé la connexion")
        return rep.rstrip("\n")

    def _reprendre(self, msg):
        """Rouvre la connexion, ré-identifie le poste puis renvoie msg."""
        self.fermer()
        if not self.ouvrir():
            raise ConnectionError("serveur injoignable")
        if self.id_biblio is not None and not msg.startswith("HELLO"):
            rep = self._echange(f"HELLO|{self.id_biblio}")
            if not rep.startswith("HELLO_OK"):
                raise ConnectionError("ré-identification refusée")
        return self._echange(msg)

    def envoyer(self, msg):
        """Envoie un message et renvoie la réponse ; se reconnecte et se ré-identifie si besoin."""
        try:
            rep = self._echange(msg)
        except (OSError, ConnectionError) as e:
            print(f"  connexion perdue ({e}) : reconnexion...")
            return self._reprendre(msg)
        if rep == "TIMEOUT":
            # le serveur a fermé pour inactivité AVANT de lire msg : msg n'a pas été traité,
            # on peut donc le renvoyer sans risque de doublon sur une nouvelle connexion
            print("  connexion fermée par le serveur (inactivité) : reconnexion...")
            return self._reprendre(msg)
        return rep


def erreur(code):
    return f"{code} - {ERREURS.get(code, 'erreur inconnue')}"


def saisir_entier(invite):
    v = input(invite).strip()
    return v if v.isdigit() and len(v) <= 9 else None


def saisir_code(invite):
    v = input(invite).strip().upper()
    return v if v.startswith("EX") and v[2:].isdigit() and 3 <= len(v[2:]) <= 10 else None


# nombre de champs attendus pour chaque réponse positive (protection contre une réponse tronquée)
NB_CHAMPS = {"HELLO_OK": 3, "ADH_OK": 6, "PRET_OK": 4, "RETOUR_OK": 5}


def bien_formee(p):
    return len(p) == NB_CHAMPS.get(p[0], len(p))


def afficher(rep):
    p = rep.split("|")
    t = p[0]
    if not bien_formee(p):
        print("  Réponse mal formée du serveur : " + rep)
    elif t == "ADH_OK":
        print(f"  Adhérent : {p[1]} {p[2]} ({p[3]}) - {p[4]} emprunt(s) en cours - amendes impayées : {p[5]} EUR")
    elif t == "PRET_OK":
        print(f"  Prêt n°{p[1]} enregistré - retour prévu le {p[2]}" + (" - réservation honorée" if p[3] == "1" else ""))
    elif t == "RETOUR_OK":
        if not p[2].isdigit():
            print("  Réponse mal formée du serveur : " + rep)
            return
        txt = f"  Retour de l'emprunt n°{p[1]} enregistré"
        if int(p[2]) > 0:
            txt += f" - retard de {p[2]} jour(s), amende de {p[3]} EUR"
        if p[4] == "1":
            txt += " - une réservation attend ce livre : mettre l'exemplaire de côté"
        print(txt)
    elif t.endswith("_KO") or t == "ERR":
        print("  Refus : " + erreur(p[1] if len(p) > 1 else "?"))
    elif t == "TIMEOUT":
        print("  Le serveur a fermé la connexion (inactivité)")
    else:
        print("  Réponse inattendue : " + rep)


def identifier(c):
    """Identification du poste (HELLO). Renvoie True si le serveur a reconnu le bibliothécaire."""
    for _ in range(ECHECS_HELLO_MAX):
        ident = saisir_entier("Identifiant du bibliothécaire : ")
        if ident is None:
            print("  identifiant invalide (entier attendu)")
            continue
        rep = c.envoyer(f"HELLO|{ident}")
        p = rep.split("|")
        if p[0] == "HELLO_OK" and bien_formee(p):
            c.id_biblio = int(ident)
            print(f"Bonjour {p[1]} {p[2]}.")
            return True
        print("  Refus : " + erreur(p[1] if len(p) > 1 else "?"))
    print("Trop d'essais d'identification : fin du programme.")
    return False


def main():
    c = Connexion()
    if not c.ouvrir():
        print("Serveur injoignable.")
        return 1
    try:
        # 1. identification du poste : sans bibliothécaire reconnu, inutile de continuer
        if not identifier(c):
            return 1
        # 2. menu
        while True:
            print("\n1) Consulter un adhérent  2) Enregistrer un prêt  3) Enregistrer un retour  4) Quitter")
            choix = input("> ").strip()
            msg = None
            if choix == "1":
                a = saisir_entier("  n° adhérent : ")
                msg = f"ADH|{a}" if a else None
            elif choix == "2":
                a = saisir_entier("  n° adhérent : ")
                e = saisir_code("  code exemplaire (ex. EX004) : ")
                msg = f"PRET|{a}|{e}" if a and e else None
            elif choix == "3":
                e = saisir_code("  code exemplaire (ex. EX001) : ")
                msg = f"RETOUR|{e}" if e else None
            elif choix == "4":
                print("  " + c.envoyer("QUIT"))
                return 0
            else:
                print("  choix invalide")
                continue
            if msg is None:
                print("  saisie invalide, rien n'est envoyé")
                continue
            afficher(c.envoyer(msg))
    except ConnectionError as e:
        print(f"Erreur : {e}")
        return 1
    except (KeyboardInterrupt, EOFError):
        print("\nInterruption : fermeture de la connexion.")
        try:
            if c.f is not None:
                c._echange("QUIT")   # pas de reconnexion juste pour dire au revoir
        except (OSError, ConnectionError):
            pass
        return 0
    finally:
        c.fermer()


if __name__ == "__main__":
    sys.exit(main())
