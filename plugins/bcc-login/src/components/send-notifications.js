import { useState, useEffect, useRef } from 'react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Toast } from 'primereact/toast';
import { Tag } from 'primereact/tag';
import { Badge } from 'primereact/badge';
        
const SendNotifications = ({ label, postId, status, targetGroupsCount, visibilityGroupsCount, isNotificationDryRun, isDirty, isAutoSaving }) => {
    const [visible, setVisible] = useState(false);
    const toast = useRef(null);
    const [nonce, setNonce] = useState(null);
    const [translations, setTranslations] = useState(null);

    useEffect(() => {
        const wpNonce = window?.wpApiSettings?.nonce || window?.bccLoginNonce;
        setNonce(wpNonce || null);
    }, []);

    const showToast = (status) => {
        const messages = {
            success: { severity: 'success', summary: 'Suksess', detail: 'Varsler sendt ut!' },
            error: { severity: 'error', summary: 'Feil', detail: 'Feil ved utsending av varsler.', sticky: true },
            info: { severity: 'info', summary: 'Info', detail: 'Sender ut varsler ...' },
        };
        toast.current.show(messages[status]);
    };

    const sendNotifications = async () => {
        if (!nonce) {
            console.error('Missing REST nonce. Ensure wpApiSettings.nonce is localized.');
            return;
        }

        try {
            setVisible(false);
            showToast('info');

            const response = await fetch('/wp-json/bcc-login/v1/send-notifications', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({ postId: postId || 0 })
            });

            if (!response.ok) {
                const text = await response.text();
                showToast('error');
                throw new Error(`Request failed (${response.status}): ${text}`);
            }

            showToast('success');
        } catch (error) {
            console.error('Error sending notifications:', error);
            showToast('error');
        }
    };

    // Fetch translations only when dialog opens (visible === true)
    useEffect(() => {
        if (!visible) return;

        if (!nonce) {
            console.error('Missing REST nonce. Ensure wpApiSettings.nonce is localized.');
            return;
        }
        if (!postId) {
            console.error('Missing postId.');
            return;
        }

        const fetchTranslations = async () => {
            try {
                const response = await fetch(`/wp-json/bcc-login/v1/wpml-translations/${postId}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': nonce
                    }
                });

                if (!response.ok) {
                    const text = await response.text().catch(() => '');
                    throw new Error(`Request failed (${response.status}): ${text}`);
                }

                // Try to parse JSON; fall back to text
                let data = null;
                try {
                    data = await response.json();
                    setTranslations(data);
                } catch {
                    const text = await response.text();
                    console.warn('Non-JSON response:', text);
                    setTranslations([]);
                }
            } catch (error) {
                console.error('Error getting WPML translations:', error);
            }
        };

        fetchTranslations();
    }, [visible, nonce, postId]);

    return (
        <div className="bcc-notifications">
            <Button type="button" label={label} onClick={() => setVisible(true)} />

            <Dialog 
                header={label}
                visible={visible} 
                onHide={() => setVisible(false)}
                loading={true}
                className="bcc-send-notifications__dialog"
            >
                <p>Status: {status === 'publish' ? <Tag icon="dashicons dashicons-yes" severity="success" value="Publisert"></Tag> : <Tag icon="dashicons dashicons-warning" severity="warning" value="IKKE publisert"></Tag>}</p>

                <div class="bcc-send-notifications__translations">
                    <p>Oversettelser: {translations === null
                        ? <Tag icon="dashicons dashicons-info" severity="info" className="italic" value="Laster inn oversettelser ..." />
                        : (translations.length == 0 ? <Tag icon="dashicons dashicons-warning" severity="warning" value="Ingen oversettelser tilgjengelig" /> : '')
                    }</p>

                    {translations !== null && translations.length > 0 ? (
                        <ul>
                            {translations.map((t) => (
                                <li key={t.id}>
                                    <div>
                                        <strong>{t.language}:</strong>{" "}
                                        {t.status === "publish" ? (
                                            <Tag icon="dashicons dashicons-yes" severity="success" value="Publisert" />
                                        ) : (
                                            <Tag icon="dashicons dashicons-warning" severity="warning" value="IKKE publisert" />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : ''}
                </div>

                <div class="bcc-send-notifications__target-groups">
                    <p>Følgende grupper kommer til å få varsel:</p>
                    {targetGroupsCount > 0 || visibilityGroupsCount > 0 ? (
                        <ul>
                            {targetGroupsCount > 0 && (
                                <li>
                                    <div>
                                        <strong>Krever handling:</strong> <Badge value={targetGroupsCount} severity="success"></Badge> gruppe(r)
                                    </div>
                                </li>
                            )}
                            {visibilityGroupsCount > 0 && (
                                <li>
                                    <div>
                                        <strong>Til informasjon:</strong> <Badge value={visibilityGroupsCount} severity="success"></Badge> gruppe(r)
                                    </div>
                                </li>
                            )}
                        </ul>
                    ) : (
                        <Tag icon="dashicons dashicons-no" severity="danger" value="Ingen grupper"></Tag>
                    )}
                </div>

                {isNotificationDryRun && (
                    <p>Test modus: <Tag icon="dashicons dashicons-no" severity="danger" value="På"></Tag></p>
                )}

                <p>Endringer: {isDirty ? <Tag icon="dashicons dashicons-warning" severity="warning" value="Ulagrede endringer"></Tag> : <Tag icon="dashicons dashicons-yes" severity="success" value="Lagret"></Tag>}</p>

                <Button type="button" label="Send" onClick={() => sendNotifications()} disabled={status !== 'publish' || (targetGroupsCount === 0 && visibilityGroupsCount === 0) || isNotificationDryRun || isDirty} />
            </Dialog>

            <Toast ref={toast} position="bottom-right" />
        </div>
    );
};

export default SendNotifications;