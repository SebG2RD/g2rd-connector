/**
 * Mode de dessin du panneau : natif dans la console du thème G2RD, ou autonome.
 *
 * - « console » : le thème G2RD a posé window.G2RDOptions.console.version ≥ 2
 *   (thème 1.41.0 et plus). Le panneau prend les classes stables de la console
 *   (contrat documenté par le thème, docs/plugin-wordpress.md) : il a l'allure des
 *   autres onglets, et la feuille du connecteur n'y ajoute que de la mise en page.
 * - « autonome » : console plus ancienne (thème 1.19 à 1.40, contrat absent).
 *   Mêmes éléments et même structure, avec les classes « g2rd-connector-… »
 *   dessinées par style.css, sous .g2rd-connector-panel--autonome.
 */

/** Version du contrat de la console à partir de laquelle ses classes existent. */
export const CONSOLE_CONTRACT_MIN = 2;

export type UiMode = 'console' | 'autonome';

/**
 * Parties de classe employées par le panneau : classes du contrat de la console
 * v2, sans le préfixe « g2rd- ». tests/Admin/ConnectorPanelTest.php vérifie
 * qu'elles restent dans le contrat et qu'elles sont dessinées en mode autonome.
 */
export type UiPart =
	| 'section'
	| 'section__head'
	| 'section__title'
	| 'section__desc'
	| 'section__footnote'
	| 'setting-list'
	| 'setting-row'
	| 'setting-row__icon'
	| 'setting-row__main'
	| 'setting-row__title'
	| 'setting-row__desc'
	| 'setting-row__control'
	| 'toggle'
	| 'chip'
	| 'chip--muted'
	| 'status'
	| 'status--ok'
	| 'status--warn'
	| 'status__dot'
	| 'field__label'
	| 'field__input'
	| 'field__hint'
	| 'notice-inline';

/**
 * Mode du panneau, lu au montage : le thème pose le contrat avant d'appeler
 * mount() (dans <head>, puis à l'exécution de son bundle). Sans console (page
 * de secours) ou avec une console sans contrat, le panneau est autonome.
 */
export function detectUiMode(): UiMode {
	const version = Number( window.G2RDOptions?.console?.version );
	return version >= CONSOLE_CONTRACT_MIN ? 'console' : 'autonome';
}

/**
 * Fabrique de classes pour un mode : cx( 'section', 'section__head' ) rend
 * « g2rd-section g2rd-section__head » en mode console, « g2rd-connector-section
 * g2rd-connector-section__head » en mode autonome. Les parties vides (condition
 * fausse) sont ignorées.
 *
 * @param mode Mode du panneau.
 */
export function classesFor(
	mode: UiMode
): ( ...parts: Array< UiPart | false > ) => string {
	return ( ...parts ) =>
		parts
			.filter( ( part ): part is UiPart => !! part )
			.map( ( part ) =>
				mode === 'console'
					? `g2rd-${ part }`
					: `g2rd-connector-${ part }`
			)
			.join( ' ' );
}
