import { useState, useEffect, useRef } from 'react';
import { Button } from 'primereact/button';
import { Toast } from 'primereact/toast';
        
const SendNotifications = ({ label, postId }) => {
    const toast = useRef(null);
    const [nonce, setNonce] = useState(null);

    useEffect(() => {
        // WordPress exposes wpApiSettings.nonce when scripts are enqueued correctly
        const wpNonce = window?.wpApiSettings?.nonce || window?.bccLoginNonce;
        setNonce(wpNonce || null);
    }, []);

    const showToast = (status) => {
        const messages = {
            success: { severity: 'success', summary: 'Success', detail: 'Notifications sent successfully!' },
            error: { severity: 'error', summary: 'Error', detail: 'Failed to send notifications.', sticky: true },
            info: { severity: 'info', summary: 'Info', detail: 'Sending notifications ...' },
        };
        toast.current.show(messages[status]);
    };

    const sendNotifications = async () => {
        if (!nonce) {
            console.error('Missing REST nonce. Ensure wpApiSettings.nonce is localized.');
            return;
        }

        try {
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
            console.error('Error sending notification:', error);
            showToast('error');
        }
    };

    return (
        <div class="bcc-notifications">
            <Button type="button" label={label} onClick={() => sendNotifications()} />
            <Toast ref={toast} position="bottom-right" />
        </div>
    );
};

export default SendNotifications;