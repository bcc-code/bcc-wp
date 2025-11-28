// src/GroupSelectionEditor.js
import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { SelectButton } from 'primereact/selectbutton';
import { Tree } from 'primereact/tree';

const GroupSelector = ({ tags, options, label, primaryName, primaryValue, primaryEmailName, primaryEmailValue, secondaryName, secondaryValue, secondaryEmailName, secondaryEmailValue, isSettingsPage, onChange }) => {
    const [visible, setVisible] = useState(false);

    const sendEmailOptions = ['Yes', 'No'];
    const [primarySendEmail, setPrimarySendEmail] = useState(() => {
        return primaryEmailValue ? primaryEmailValue : sendEmailOptions[0]
    });
    const [secondarySendEmail, setSecondarySendEmail] = useState(() => {
        return secondaryEmailValue ? secondaryEmailValue : sendEmailOptions[1]
    });

    const [treeNodes] = useState(() => {
        const optionGroups = [];

        // Add items to groups based on tags
        tags.forEach(tag => {
            const items = options.filter(option => 
                option.tags && 
                option.tags.indexOf(tag) != -1
            ).sort((a, b) => a.name > b.name ? 1 : -1);
            
            if (items.length > 0) {
                optionGroups.push({
                    tag: tag,
                    items: items
                });
            }
        });

        // Transform to Tree node format
        return optionGroups.map((group, groupIndex) => ({
            key: groupIndex.toString(),
            label: group.tag,
            children: group.items.map((item) => ({
                key: item.uid,
                label: item.name
            }))
        }));
    });

    const [primaryExpandedKeys, setPrimaryExpandedKeys] = useState({});
    const [secondaryExpandedKeys, setSecondaryExpandedKeys] = useState({});

    const [primarySelectedGroups, setPrimarySelectedGroups] = useState(() => {
        // Initialize selected groups from value prop
        const selectedUids = primaryValue ? primaryValue.split(',') : [];
        const primarySelectedGroupsKeys = {};

        selectedUids.forEach(uid => {
            primarySelectedGroupsKeys[uid] = { checked: true };

            // Also mark parent as partially checked (an uid can belong to more than one group)
            treeNodes.forEach((group, groupIndex) => {
                if (group.children.some(item => item.key === uid)) {
                    if (!primarySelectedGroupsKeys[groupIndex.toString()]) {
                        primarySelectedGroupsKeys[groupIndex.toString()] = { checked: false, partialChecked: true };
                    }
                }
            });
        });

        // Check if all children of a group are selected to mark parent as checked
        treeNodes.forEach((group, groupIndex) => {
            const allChildrenSelected = group.children.every(item => 
                Object.keys(primarySelectedGroupsKeys).includes(item.key)
            );

            if (allChildrenSelected) {
                primarySelectedGroupsKeys[groupIndex.toString()] = { checked: true };
            }
        });

        return primarySelectedGroupsKeys;
    });

    const [secondarySelectedGroups, setSecondarySelectedGroups] = useState(() => {
        // Initialize selected groups from value prop
        const selectedUids = secondaryValue ? secondaryValue.split(',') : [];
        const secondarySelectedGroupsKeys = {};

        selectedUids.forEach(uid => {
            secondarySelectedGroupsKeys[uid] = { checked: true };

            // Also mark parent as partially checked (an uid can belong to more than one group)
            treeNodes.forEach((group, groupIndex) => {
                if (group.children.some(item => item.key === uid)) {
                    if (!secondarySelectedGroupsKeys[groupIndex.toString()]) {
                        secondarySelectedGroupsKeys[groupIndex.toString()] = { checked: false, partialChecked: true };
                    }
                }
            });
        });

        // Check if all children of a group are selected to mark parent as checked
        treeNodes.forEach((group, groupIndex) => {
            const allChildrenSelected = group.children.every(item => 
                Object.keys(secondarySelectedGroupsKeys).includes(item.key)
            );

            if (allChildrenSelected) {
                secondarySelectedGroupsKeys[groupIndex.toString()] = { checked: true };
            }
        });

        return secondarySelectedGroupsKeys;
    });

    const primaryOnSelectionChange = (e) => {
        setPrimarySelectedGroups(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(e.value),
            primarySendEmail,
            onlyPostGroups(secondarySelectedGroups),
            secondarySendEmail
        );
    };

    const primaryEmailOnChange = (e) => {
        setPrimarySendEmail(e.value)

        if (!onChange) return;

        onChange(
            onlyPostGroups(primarySelectedGroups),
            e.value,
            onlyPostGroups(secondarySelectedGroups),
            secondarySendEmail
        );
    };

    const secondaryOnSelectionChange = (e) => {
        setSecondarySelectedGroups(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(primarySelectedGroups),
            primarySendEmail,
            onlyPostGroups(e.value),
            secondarySendEmail
        );
    };

    const secondaryEmailOnChange = (e) => {
        setSecondarySendEmail(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(primarySelectedGroups),
            primarySendEmail,
            onlyPostGroups(secondarySelectedGroups),
            e.value
        );
    };

    const getInitialPrimaryExpandedKeys = () => {
        const initialExpandedKeys = {};

        treeNodes.forEach((group, groupIndex) => {
            const hasSelectedChild = group.children.some(item =>
                Object.keys(primarySelectedGroups).includes(item.key)
            );

            if (hasSelectedChild) {
                initialExpandedKeys[groupIndex.toString()] = true;
            }
        });

        return initialExpandedKeys;
    }

    const getInitialSecondaryExpandedKeys = () => {
        const initialExpandedKeys = {};

        treeNodes.forEach((group, groupIndex) => {
            const hasSelectedChild = group.children.some(item =>
                Object.keys(secondarySelectedGroups).includes(item.key)
            );

            if (hasSelectedChild) {
                initialExpandedKeys[groupIndex.toString()] = true;
            }
        });

        return initialExpandedKeys;
    }

    const getAllKeys = () => {
        const allExpandedKeys = {};

        treeNodes.forEach((group, groupIndex) => {
            allExpandedKeys[groupIndex.toString()] = true;
        });

        return allExpandedKeys;
    };

    const primaryHandleToggle = (e) => {
        if (e.originalEvent === null && Object.keys(e.value).length === 0) {
            setPrimaryExpandedKeys(getInitialPrimaryExpandedKeys());
            return;
        }

        setPrimaryExpandedKeys(e.value);
    };

    const secondaryHandleToggle = (e) => {
        if (e.originalEvent === null && Object.keys(e.value).length === 0) {
            setSecondaryExpandedKeys(getInitialSecondaryExpandedKeys());
            return;
        }

        setSecondaryExpandedKeys(e.value);
    };

    const onlyPostGroups = (selectedGroups) => {
        const groupTags = options.map(option => option.uid);
        return Object.keys(selectedGroups).filter(uid => groupTags.includes(uid));
    }

    return (
        <div>
            {label && <h2 htmlFor={primaryName}>{label}</h2>}

            <div class="post-groups-selector">
                <Button type="button" label="Select" onClick={() => setVisible(true)} />
                <p class="post-groups-count">{onlyPostGroups(primarySelectedGroups).length + onlyPostGroups(secondarySelectedGroups).length} group(s) selected</p>
            </div>

            <Dialog 
                header="Post Groups" 
                visible={visible} 
                onHide={() => setVisible(false)}
                loading={true}
                className="bcc-group-selector__dialog"
            >
                <div id="primary-groups-selector">
                    { !isSettingsPage && ( <h3>Primary</h3> ) }

                    <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                        <Button type="button" icon="dashicons dashicons-plus" label="Expand All" onClick={() => setPrimaryExpandedKeys(getAllKeys())} />
                        <Button type="button" icon="dashicons dashicons-minus" label="Collapse All" onClick={() => setPrimaryExpandedKeys({})} />
                    </div>

                    <Tree 
                        value={treeNodes}
                        filter
                        filterPlaceholder="Search group ..."
                        filterDelay={100}
                        filterMode="lenient"
                        selectionMode="checkbox"
                        selectionKeys={primarySelectedGroups}
                        onSelectionChange={primaryOnSelectionChange}
                        expandedKeys={primaryExpandedKeys}
                        onToggle={primaryHandleToggle}
                        emptyMessage="No groups match your search."
                    />

                    { !isSettingsPage && ( <div className="flex flex-wrap gap-2 mb-4 items-center">
                        <h4>Send Email:</h4>
                        <SelectButton value={primarySendEmail} onChange={primaryEmailOnChange} options={sendEmailOptions} />
                    </div> ) }
                </div>

                { !isSettingsPage && (
                    <div id="secondary-groups-selector">
                        <h3>Secondary</h3>
                        <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                            <Button type="button" icon="dashicons dashicons-plus" label="Expand All" onClick={() => setSecondaryExpandedKeys(getAllKeys())} />
                            <Button type="button" icon="dashicons dashicons-minus" label="Collapse All" onClick={() => setSecondaryExpandedKeys({})} />
                        </div>

                        <Tree 
                            value={treeNodes}
                            filter
                            filterPlaceholder="Search group ..."
                            filterDelay={100}
                            filterMode="lenient"
                            selectionMode="checkbox"
                            selectionKeys={secondarySelectedGroups}
                            onSelectionChange={secondaryOnSelectionChange}
                            expandedKeys={secondaryExpandedKeys}
                            onToggle={secondaryHandleToggle}
                            emptyMessage="No groups match your search."
                        />

                        <div className="flex flex-wrap gap-2 mb-4 items-center">
                            <h4>Send Email:</h4>
                            <SelectButton value={secondarySendEmail} onChange={secondaryEmailOnChange} options={sendEmailOptions} />
                        </div>
                    </div>
                ) }
            </Dialog>

            <input type="hidden" name={primaryName} value={onlyPostGroups(primarySelectedGroups).join(',')} />

            { !isSettingsPage && (
                <div>
                    <input type="hidden" name={primaryEmailName} value={primarySendEmail} />
                    <input type="hidden" name={secondaryName} value={onlyPostGroups(secondarySelectedGroups).join(',')} />
                    <input type="hidden" name={secondaryEmailName} value={secondarySendEmail} />
                </div>
            ) }
        </div>
    );
};

export default GroupSelector;