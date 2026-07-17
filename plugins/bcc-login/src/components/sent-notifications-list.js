import { useState, useEffect, useRef } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { __ } from '@wordpress/i18n';
import { Button } from 'primereact/button';
import { Toast } from 'primereact/toast';

const SentNotificationsList = ({ sentNotifications, postId }) => {
    const [notifications, setNotifications] = useState(sentNotifications);
    const [nonce, setNonce] = useState(null);
    const toast = useRef(null);

    useEffect(() => {
        function handler(notifications) {
            setNotifications(notifications.detail);
        };

        window.addEventListener('bcc:notificationsUpdated', handler);
        return () => window.removeEventListener('bcc:notificationsUpdated', handler);
    }, []);

    useEffect(() => {
        const wpNonce = window?.wpApiSettings?.nonce || window?.bccLoginNonce;
        setNonce(wpNonce || null);
    }, []);

    async function refreshStatistics(notificationId) {
        try {
            showToast('info');

            const response = await fetch('/wp-json/bcc-login/v1/refresh-notification-statistics', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({ postId: postId || 0, notificationId })
            });

            if (!response.ok) {
                const text = await response.text();
                throw new Error(`Request failed (${response.status}): ${text}`);
            }

            const newNotifications = await response.json()
            setNotifications(newNotifications)
            showToast('success');
        } catch (error) {
            console.error('Error refreshing notification statistics:', error);
            showToast('error');
        }
    }

    function showToast(status){
        const messages = {
            success: { severity: 'success', summary: __('Success', 'bcc-login'), detail: __('Refreshed notification statistics!', 'bcc-login'), life: 5000 },
            error: { severity: 'error', summary: __('Error', 'bcc-login'), detail: __('Error refreshing notification statistics.', 'bcc-login'), sticky: true },
            info: { severity: 'info', summary: __('Info', 'bcc-login'), detail: __('Refreshing notification statistics...', 'bcc-login'), sticky: true },
        };
        toast.current.remove(messages.info);
        toast.current.show(messages[status]);
    };

    const formattedNotifications = notifications
        .toSorted((a, b) => new Date(b.date) - new Date(a.date))
        .map(notification => ({
          date: (new Date(notification.date)).toLocaleString(),
          refresh_date: notification.refresh_date && (new Date(notification.refresh_date)).toLocaleString(),
          no_of_groups: notification.notification_groups?.length,
          id: notification.id,
          type: formatNotificationType(notification.type),
          total: notification.total,
          sent: notification.sent,
          delivered: notification.delivered,
          error: notification.error
        }));

    function formatNotificationType(type) {
        if(type === 'target_groups') return __("Action required", "bcc-login")
        if(type === 'visibility_groups') return __("For information", "bcc-login")
        return undefined
    }

    const notificationFields = {
        'date': 'Sent on',
        'type': 'Type',
        'no_of_groups': 'No. of groups',
        'refresh_date': 'Refreshed on',
    }

    const deliveryStatistics = {
        total: 'Total',
        sent: 'Sent',
        delivered: 'Delivered',
        error: 'Error'
    }

    return (
        <div className="bcc-sent-notifications-list" style={{ width: "100%"}}>
            {formattedNotifications.map(notification => 
            <div key={notification.id} style={{ borderBottom: '1px solid rgba(0,0,0,.133)', width: "100%"}}>
                <ul>
                    {Object.entries(notificationFields).map(([key, value]) => {
                        if(notification[key] != undefined) {
                            return <li key={key}><b>{__(value, "bcc-login")}: </b>{notification[key]}</li>
                        }
                    })}
                    <li style={{display: notification.id ? 'block' : 'none'}} >
                        <div style={{display: 'flex', justifyContent: 'space-between', alignItems: 'center'}}>
                            <h4 style={{margin: "0px"}}>{__("Delivery statistics", "bcc-login")}</h4>
                            <Button style={{paddingBlock: 0}} text label={__("Refresh", "bcc-login")} onClick={() => refreshStatistics(notification.id)} />
                        </div>

                        <DataTable value={[notification]} style={{marginBottom: '1rem'}}>
                            {Object.entries(deliveryStatistics).map(([key, value]) => (
                                <Column key={key} field={key} header={__(value, "bcc-login")} bodyStyle={{ textAlign: 'center', border: 'none', paddingBottom: 0 }}></Column>
                            ))}
                        </DataTable>
                    </li>
                </ul>
            </div>)}
            <Toast ref={toast} position="bottom-right" />
        </div>
    );
};

export default SentNotificationsList;