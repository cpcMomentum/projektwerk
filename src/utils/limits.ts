/** Lange Texte bleiben ohne maxlength, damit Einfügen nicht still abschneidet; dort meldet der Server. */
export const LIMITS = {
	ticketTitle: 255,
	stepTitle: 255,
} as const
