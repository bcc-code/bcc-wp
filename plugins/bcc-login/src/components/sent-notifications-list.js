import { useState, useEffect } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { __ } from '@wordpress/i18n';

const SentNotificationsList = ({ sentNotifications }) => {
    const [notifications, setNotifications] = useState(sentNotifications || []);

    // Rebuild notifications when sentNotifications change via external Send Notifications component
    useEffect(() => {
        const rebuild = (newNotifications) => {
            setNotifications(newNotifications);
        };

        const handler = (ev) => {
            const { date, no_of_groups } = ev.detail || {};
            const newNotifications = [...notifications, { date, no_of_groups }];

            rebuild(newNotifications);
        };

        window.addEventListener('bcc:notificationSent', handler);
        return () => window.removeEventListener('bcc:notificationSent', handler);
    }, [sentNotifications]);

    return (
        <div className="bcc-sent-notifications-list">
            <DataTable value={notifications} emptyMessage={__("No notifications sent yet.", "bcc-login")} sortField="date" sortOrder={-1}>
                <Column field="date" header={__("Sent on", "bcc-login")}></Column>
                <Column field="no_of_groups" header={__("No. of groups", "bcc-login")} headerStyle={{ textAlign: 'center' }} bodyStyle={{ textAlign: 'center' }}></Column>
            </DataTable>
        </div>
    );
};

export default SentNotificationsList;