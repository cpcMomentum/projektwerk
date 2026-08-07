declare module '*.vue' {
	import type { DefineComponent } from 'vue'

	// Bewusst `object`/`unknown` statt der ueblichen `{}, {}, any`-Signatur:
	// `{}` erlaubt jeden nicht-nullish Wert (auch `0` und `""`), und `any`
	// schaltet die Pruefung ganz ab. Fuer einen Modul-Shim reicht die
	// schwaechere, aber ehrliche Form — die echten Props kommen ohnehin aus
	// der jeweiligen Komponente.
	const component: DefineComponent<object, object, unknown>
	export default component
}
