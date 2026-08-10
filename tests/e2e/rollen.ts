/**
 * Die beiden Seiten, um die sich diese App dreht.
 *
 * **Kein Gast aus der Guests-App.** Unsere Kundenseite ist ein ganz normales
 * Nextcloud-Konto; was sie zur Kundenseite macht, ist `role = 'external'` in
 * `pwerk_members`, nicht ihr Kontotyp. Genau deshalb haengt die App an keiner
 * anderen App — und genau deshalb sind diese Tests billig.
 *
 * Die Konten legt `tests/e2e/provision.sh` an, lokal wie in der CI derselbe
 * Weg.
 */

export const PASSWORT = process.env.PWERK_E2E_PASSWORT ?? 'e2e-Pw-2026-Test!'

export interface Rolle {
	uid: string
	name: string
	/** Ablage der angemeldeten Sitzung, von `auth.setup.ts` geschrieben. */
	sitzung: string
}

export const INTERN: Rolle = {
	uid: 'pw-e2e-intern',
	name: 'E2E Dienstleisterseite',
	sitzung: 'tests/e2e/.sitzungen/intern.json',
}

export const KUNDE: Rolle = {
	uid: 'pw-e2e-kunde',
	name: 'E2E Kundenseite',
	sitzung: 'tests/e2e/.sitzungen/kunde.json',
}

export const ROLLEN = [INTERN, KUNDE]

/**
 * Der Weg zur App — immer mit `index.php`.
 *
 * In der CI laeuft Nextcloud unter `php -S` und kennt kein Rewrite; ohne
 * `index.php` gaebe es dort 404. Lokal ginge es auch ohne, aber ein Test, der
 * lokal einen anderen Weg nimmt als in der CI, schweigt genau dort, wo er
 * reden muesste.
 */
export const APP_PFAD = '/index.php/apps/projektwerk/'
