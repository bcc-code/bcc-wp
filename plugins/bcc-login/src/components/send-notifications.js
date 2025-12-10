import { useState, useEffect, useRef } from 'react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Toast } from 'primereact/toast';
import { Tag } from 'primereact/tag';
import { Badge } from 'primereact/badge';
import { __ } from '@wordpress/i18n';

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
            success: { severity: 'success', summary: __('Success', 'bcc-login'), detail: __('Notifications sent!', 'bcc-login') },
            error: { severity: 'error', summary: __('Error', 'bcc-login'), detail: __('Error sending notifications.', 'bcc-login'), sticky: true },
            info: { severity: 'info', summary: __('Info', 'bcc-login'), detail: __('Sending notifications ...', 'bcc-login') },
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
                <p>{__('Status', 'bcc-login')}: {status === 'publish' ? <Tag icon="dashicons dashicons-yes" severity="success" value={__('Published', 'bcc-login')} /> : <Tag icon="dashicons dashicons-warning" severity="warning" value={__('NOT published', 'bcc-login')} />}</p>

                <div class="bcc-send-notifications__translations">
                    <p>{__('Translations', 'bcc-login')}: {translations === null
                        ? <Tag icon="dashicons dashicons-info" severity="info" className="italic" value={__('Loading translations ...', 'bcc-login')} />
                        : (translations.length == 0 ? <Tag icon="dashicons dashicons-warning" severity="warning" value={__('No translations available', 'bcc-login')} /> : '')
                    }</p>

                    {translations !== null && translations.length > 0 ? (
                        <ul>
                            {translations.map((t) => (
                                <li key={t.id}>
                                    <div>
                                        <strong>{t.language}:</strong>{" "}
                                        {t.status === "publish" ? (
                                            <Tag icon="dashicons dashicons-yes" severity="success" value={__('Published', 'bcc-login')} />
                                        ) : (
                                            <Tag icon="dashicons dashicons-warning" severity="warning" value={__('NOT published', 'bcc-login')} />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : ''}
                </div>

                <div class="bcc-send-notifications__target-groups">
                    <p>{__('The following groups will get notified:', 'bcc-login')}</p>
                    {targetGroupsCount > 0 || visibilityGroupsCount > 0 ? (
                        <ul>
                            {targetGroupsCount > 0 && (
                                <li>
                                    <div>
                                        <strong>{__('Requires action:', 'bcc-login')}</strong> <Badge value={targetGroupsCount} severity="success"></Badge> {__('group(s)', 'bcc-login')}
                                    </div>
                                </li>
                            )}
                            {visibilityGroupsCount > 0 && (
                                <li>
                                    <div>
                                        <strong>{__('For information:', 'bcc-login')}</strong> <Badge value={visibilityGroupsCount} severity="success"></Badge> {__('group(s)', 'bcc-login')}
                                    </div>
                                </li>
                            )}
                        </ul>
                    ) : (
                        <Tag icon="dashicons dashicons-no" severity="danger" value={__('No groups', 'bcc-login')}></Tag>
                    )}
                </div>

                {isNotificationDryRun && (
                    <p>{__('Test mode:', 'bcc-login')} <Tag icon="dashicons dashicons-no" severity="danger" value={__('On', 'bcc-login')}></Tag></p>
                )}

                <p>{__('Changes:', 'bcc-login')} {isDirty ? <Tag icon="dashicons dashicons-warning" severity="warning" value={__('Unsaved changes', 'bcc-login')}></Tag> : <Tag icon="dashicons dashicons-yes" severity="success" value={__('Saved', 'bcc-login')}></Tag>}</p>

                <Button type="button" label={__('Send', 'bcc-login')} onClick={() => sendNotifications()} disabled={status !== 'publish' || (targetGroupsCount === 0 && visibilityGroupsCount === 0) || isNotificationDryRun || isDirty} />
            </Dialog>

            <Toast ref={toast} position="bottom-right" />
        </div>
    );
};

export default SendNotifications;