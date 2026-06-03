/**
 * Panneau principal du plugin G2RD Connector — état + actions.
 *
 * Affiché soit standalone (menu top-level), soit comme tab dans Options G2RD.
 */

import { useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Button, ToggleControl, Notice, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import type { ConnectorBootData } from './index';

interface Props {
    data: ConnectorBootData;
}

export function ConnectorPanel({ data }: Props): JSX.Element {
    const [state, setState] = useState(data);
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState<string | null>(null);

    const testHealth = async (): Promise<void> => {
        setTesting(true);
        setTestResult(null);
        try {
            const resp = await apiFetch<{ ok: boolean; connector_version: string }>({
                path: 'g2rd/v1/health',
            });
            setTestResult(`OK — connector v${resp.connector_version}`);
        } catch (e) {
            setTestResult(`Échec : ${(e as Error).message}`);
        } finally {
            setTesting(false);
        }
    };

    return (
        <div className="g2rd-connector-panel">
            <Card>
                <CardHeader>
                    <h2>G2RD Connector</h2>
                    <span className="g2rd-connector-version">v{state.connectorVersion}</span>
                </CardHeader>
                <CardBody>
                    {state.enrolled ? (
                        <Notice status="success" isDismissible={false}>
                            Site enrôlé — connecté à <code>{state.managerUrl}</code> en tant que site #{state.siteId}.
                            {state.lastHeartbeatAt && (
                                <> Dernier heartbeat : {new Date(state.lastHeartbeatAt).toLocaleString()}.</>
                            )}
                        </Notice>
                    ) : (
                        <Notice status="warning" isDismissible={false}>
                            Site non enrôlé. Renseignez l'URL du manager et le token d'invitation pour vous connecter.
                        </Notice>
                    )}

                    <TextControl
                        label="URL du manager"
                        value={state.managerUrl}
                        onChange={(v) => setState({ ...state, managerUrl: v })}
                        help="Ex : https://wp-manager.g2rd.fr"
                    />

                    <ToggleControl
                        label="Heartbeat horaire"
                        help="Le plugin envoie ses métriques au manager toutes les heures."
                        checked={state.heartbeatEnabled}
                        onChange={(v) => setState({ ...state, heartbeatEnabled: v })}
                    />

                    <ToggleControl
                        label="Webhook events"
                        help="Notifier le manager en temps réel (logins, plugins, mises à jour)."
                        checked={state.eventsEnabled}
                        onChange={(v) => setState({ ...state, eventsEnabled: v })}
                    />

                    <ToggleControl
                        label="Commandes distantes"
                        help="Autoriser le manager à déclencher clear_cache / update_core à distance."
                        checked={state.remoteCommandsEnabled}
                        onChange={(v) => setState({ ...state, remoteCommandsEnabled: v })}
                    />

                    <div className="g2rd-connector-actions">
                        <Button variant="secondary" onClick={testHealth} isBusy={testing}>
                            Tester la connectivité
                        </Button>
                        {testResult && <span className="g2rd-connector-test-result">{testResult}</span>}
                    </div>
                </CardBody>
            </Card>
        </div>
    );
}
