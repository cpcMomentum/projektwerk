import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Der Workflow `close-issues-on-develop.yml` schliesst Issues anhand von
 * Schluesselwoertern im PR-Text. Beide bekannten Fehlgriffe waren *still* —
 * der Lauf meldet `success`, egal ob er zu wenig findet (die ss/ß-Luecke) oder
 * zu viel (die Beispieltabelle aus PR #24, die #3, #7 und #8 geschlossen hat,
 * obwohl keins erledigt war).
 *
 * Ein stiller Fehlgriff braucht einen lauten Waechter. Dieser Test loest
 * `stripCode` und `pattern` AUS DER YAML-DATEI heraus und fuehrt sie aus — er
 * prueft damit das Original und nicht eine Kopie, die neben ihm veraltet.
 */

const WORKFLOW = resolve(
	import.meta.dirname,
	'../../.github/workflows/close-issues-on-develop.yml',
)

interface Extracted {
	stripCode: (text: string) => string
	pattern: RegExp
}

function extract(): Extracted {
	const yml = readFileSync(WORKFLOW, 'utf8')
	const block = yml.split('script: |\n')[1]
	expect(block, 'script-Block im Workflow gefunden').toBeDefined()

	// Der Block ist ein YAML-Literal mit 12 Zeichen Einrueckung.
	const src = block.split('\n').map((line) => line.replace(/^ {12}/, '')).join('\n')

	const stripSrc = src.match(/const stripCode = \(text\) => \{[\s\S]*?\n\}/m)
	const patternSrc = src.match(/const pattern = \/.*\/gi/)
	expect(stripSrc, 'stripCode im Workflow gefunden').not.toBeNull()
	expect(patternSrc, 'pattern im Workflow gefunden').not.toBeNull()

	return new Function(`${stripSrc![0]}\n${patternSrc![0]}\nreturn { stripCode, pattern }`)() as Extracted
}

const { stripCode, pattern } = extract()

/** Dieselbe Auswertung wie im Workflow: Titel und Text, Duplikate entfernt. */
function referenced(title: string, body = ''): number[] {
	pattern.lastIndex = 0
	const haystack = stripCode(`${title}\n\n${body}`)
	return [...new Set([...haystack.matchAll(pattern)].map((m) => Number(m[1])))]
}

describe('close-issues: Fliesstext wird erkannt', () => {
	it.each([
		['ss-Schreibweise', 'Schliesst #4.', [4]],
		['ß-Schreibweise', 'Schließt #4.', [4]],
		['loest und behebt', 'Löst #7 und behebt #8', [7, 8]],
		['geloest durch', 'Gelöst durch #99', [99]],
		['englische Schluesselwoerter', 'Closes #1, fixes #2, resolved #3', [1, 2, 3]],
		['umgesetzt mit Doppelpunkt', 'Umgesetzt: #55', [55]],
	])('%s', (_name, body, expected) => {
		expect(referenced('Beliebiger Titel', body)).toEqual(expected)
	})

	it('findet die Referenz auch im Titel', () => {
		expect(referenced('fix(x): behebt #212')).toEqual([212])
	})
})

describe('close-issues: was keine Anweisung ist, schliesst nichts', () => {
	it.each([
		['blosse Erwaehnung', 'Siehe #42 fuer Kontext'],
		['Treffer in der Wortmitte', 'verschliesst #5'],
		['Inline-Code', 'Beispiel: `Löst #7 und behebt #8` in der Tabelle'],
		['Zaun aus Backticks', 'Vorher:\n\n```\nSchliesst #4\n```\n\nNachher.'],
		['Zaun aus Tilden', 'Vorher:\n\n~~~js\nCloses #1\n~~~\n\nNachher.'],
		['Zaun mit Sprachkennung', '```diff\n-behebt #13\n+behebt #14\n```'],
		['eingerueckter Zaun', '  ```\n  fixes #21\n  ```'],
		['unpaariger Zaun beendet die Auswertung', 'Text\n\n```\nabgeschnitten, behebt #66'],
	])('%s', (_name, body) => {
		expect(referenced('Beliebiger Titel', body)).toEqual([])
	})

	it('liest die Gegenprobe-Tabelle aus PR #24 nicht als Auftrag', () => {
		const body = [
			'| Eingabe | Erkannt |',
			'|---|---|',
			'| `Schließt #4.` | `[4]` |',
			'| `Löst #7 und behebt #8` | `[7, 8]` |',
			'| `Closes #1, fixes #2, resolved #3` | `[1, 2, 3]` |',
		].join('\n')
		expect(referenced('fix(ci): Eszett', body)).toEqual([])
	})
})

describe('close-issues: Fliesstext und Code nebeneinander', () => {
	it('zaehlt den Fliesstext, wenn Code folgt', () => {
		const body = 'Behebt #10.\n\n```\nfrueher: behebt #11\n```\n\nSiehe `fixes #12` als Beispiel.'
		expect(referenced('Titel', body)).toEqual([10])
	})

	it('zaehlt den Fliesstext, wenn Code vorangeht', () => {
		expect(referenced('Titel', '```\nfixes #30\n```\n\nSchließt #31.')).toEqual([31])
	})
})

/**
 * Aus dem Review zu PR #26. Zwei der drei Befunde waren echt und versagten zur
 * falschen Seite — sie gaben Code als Fliesstext aus und haetten wieder
 * schliessen koennen, was niemand schliessen wollte: die vorige Fassung las
 * aus dem 4er-Zaun `#40` und aus dem mehrzeiligen Span `#50, #51`.
 *
 * Der dritte Befund (CRLF breche die $-Pruefung am Schlusszaun) traf nicht zu:
 * JavaScript zaehlt \r selbst als Zeilenende. Der Fall bleibt trotzdem als
 * Test stehen, damit die Annahme belegt ist und nicht erneut geraten wird.
 */
describe('close-issues: Randfaelle aus dem Review zu #26', () => {
	it('beendet einen 4er-Zaun nicht an einem inneren 3er-Zaun', () => {
		const body = [
			'````markdown',
			'Beispiel fuer die Doku:',
			'```',
			'behebt #40',
			'```',
			'und ausserdem loest #41',
			'````',
		].join('\n')
		expect(referenced('Titel', body)).toEqual([])
	})

	it('entfernt Inline-Code, der ueber eine Zeile hinausgeht', () => {
		expect(referenced('Titel', 'Beispiel: `Schliesst #50\nund behebt #51` als Zitat.')).toEqual([])
	})

	it('verschluckt ueber eine Leerzeile hinweg nichts', () => {
		// Zwei verirrte Backticks in getrennten Absaetzen sind kein Code-Span.
		// Wuerde die Grenze fehlen, ginge die Referenz dazwischen verloren.
		expect(referenced('Titel', 'Ein ` Backtick.\n\nBehebt #60.\n\nNoch ein ` Backtick.')).toEqual([60])
	})

	it('kommt mit CRLF-Zeilenenden zurecht', () => {
		const body = 'Behebt #70.\r\n\r\n```\r\nbehebt #71\r\n```\r\n\r\nUnd loest #72.'
		expect(referenced('Titel', body)).toEqual([70, 72])
	})
})
