import { useState, useEffect } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { __ } from '@wordpress/i18n';

const SentNotificationsList = ({ sentNotifications }) => {
    const [optimistic, setOptimistic] = useState([]);

    useEffect(() => {
        const handler = (ev) => {
            const { date, no_of_groups } = ev.detail || {};
            setOptimistic(prev => [{ date, no_of_groups }, ...prev]);
        };

        window.addEventListener('bcc:notificationSent', handler);
        return () => window.removeEventListener('bcc:notificationSent', handler);
    }, []);

    const allNotifications = [...optimistic, ...(sentNotifications || [])];

    return (
        <div className="bcc-sent-notifications-list">
            <DataTable value={allNotifications} emptyMessage={__("No notifications sent yet.", "bcc-login")}>
                <Column field="date" header={__("Sent on", "bcc-login")}></Column>
                <Column field="no_of_groups" header={__("No. of groups", "bcc-login")} headerStyle={{ textAlign: 'center' }} bodyStyle={{ textAlign: 'center' }}></Column>
            </DataTable>
        </div>
    );
};

export default SentNotificationsList;