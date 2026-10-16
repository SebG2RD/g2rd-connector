/**
 * Icônes linéaires des lignes de réglage (SVG en ligne, 24 × 24, trait 1,75,
 * comme le jeu d'icônes de la console du thème G2RD). Décoratives : le titre de
 * la ligne porte le sens, elles sont donc masquées aux lecteurs d'écran.
 */

export type IconName =
	'pulse' | 'bolt' | 'terminal' | 'globe' | 'login' | 'shield';

const PATHS: Record< IconName, JSX.Element > = {
	// Signe de vie horaire : tracé de pouls.
	pulse: <path d="M3 12h4l2.5-6 5 12 2.5-6h4" />,
	// Événements en temps réel : éclair.
	bolt: <path d="M13 3L5 13.5h6L10 21l8-10.5h-6z" />,
	// Commandes à distance : invite de terminal.
	terminal: (
		<>
			<rect x="3" y="4" width="18" height="16" rx="2" />
			<path d="M7 9.5l3 2.5-3 2.5" />
			<path d="M13 15h4" />
		</>
	),
	// IPv4 vers le manager : globe.
	globe: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M3.5 12h17" />
			<path d="M12 3.5c2.4 2.5 3.5 5.3 3.5 8.5s-1.1 6-3.5 8.5c-2.4-2.5-3.5-5.3-3.5-8.5s1.1-6 3.5-8.5z" />
		</>
	),
	// Connexion directe depuis G2RD : flèche qui entre.
	login: (
		<>
			<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" />
			<path d="M4 12h11" />
			<path d="M11 8l4 4-4 4" />
		</>
	),
	// Exiger des commandes signées : bouclier (même tracé que la console).
	shield: <path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6z" />,
};

export function Icon( { name }: { name: IconName } ): JSX.Element {
	return (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.75"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			{ PATHS[ name ] }
		</svg>
	);
}
