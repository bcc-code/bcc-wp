// src/GroupSelectionEditor.js
import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { SelectButton } from 'primereact/selectbutton';
import { Tree } from 'primereact/tree';

const GroupSelector = ({ tags, options, label, targetGroupsName, targetGroupsValue, sendEmailToTargetGroupsName, sendEmailToTargetGroupsValue, visibilityGroupsName, visibilityGroupsValue, sendEmailToVisibilityGroupsName, sendEmailToVisibilityGroupsValue, isSettingsPage, onChange }) => {
    const [visible, setVisible] = useState(false);

    const sendEmailOptions = ['Yes', 'No'];
    const [sendEmailToTargetGroups, setSendEmailToTargetGroups] = useState(() => {
        return sendEmailToTargetGroupsValue ? sendEmailToTargetGroupsValue : sendEmailOptions[0]
    });
    const [sendEmailToVisibilityGroups, setSendEmailToVisibilityGroups] = useState(() => {
        return sendEmailToVisibilityGroupsValue ? sendEmailToVisibilityGroupsValue : sendEmailOptions[1]
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

    const [targetGroupsExpandedKeys, setTargetGroupsExpandedKeys] = useState({});
    const [visibilityGroupsExpandedKeys, setVisibilityGroupsExpandedKeys] = useState({});

    const [targetGroupsSelected, setTargetGroupsSelected] = useState(() => {
        // Initialize selected groups from value prop
        const selectedUids = targetGroupsValue ? targetGroupsValue.split(',') : [];
        const targetGroupsSelectedKeys = {};

        selectedUids.forEach(uid => {
            targetGroupsSelectedKeys[uid] = { checked: true };

            // Also mark parent as partially checked (an uid can belong to more than one group)
            treeNodes.forEach((group, groupIndex) => {
                if (group.children.some(item => item.key === uid)) {
                    if (!targetGroupsSelectedKeys[groupIndex.toString()]) {
                        targetGroupsSelectedKeys[groupIndex.toString()] = { checked: false, partialChecked: true };
                    }
                }
            });
        });

        // Check if all children of a group are selected to mark parent as checked
        treeNodes.forEach((group, groupIndex) => {
            const allChildrenSelected = group.children.every(item => 
                Object.keys(targetGroupsSelectedKeys).includes(item.key)
            );

            if (allChildrenSelected) {
                targetGroupsSelectedKeys[groupIndex.toString()] = { checked: true };
            }
        });

        return targetGroupsSelectedKeys;
    });

    const [visibilityGroupsSelected, setVisibilityGroupsSelected] = useState(() => {
        // Initialize selected groups from value prop
        const selectedUids = visibilityGroupsValue ? visibilityGroupsValue.split(',') : [];
        const visibilityGroupsSelectedKeys = {};

        selectedUids.forEach(uid => {
            visibilityGroupsSelectedKeys[uid] = { checked: true };

            // Also mark parent as partially checked (an uid can belong to more than one group)
            treeNodes.forEach((group, groupIndex) => {
                if (group.children.some(item => item.key === uid)) {
                    if (!visibilityGroupsSelectedKeys[groupIndex.toString()]) {
                        visibilityGroupsSelectedKeys[groupIndex.toString()] = { checked: false, partialChecked: true };
                    }
                }
            });
        });

        // Check if all children of a group are selected to mark parent as checked
        treeNodes.forEach((group, groupIndex) => {
            const allChildrenSelected = group.children.every(item => 
                Object.keys(visibilityGroupsSelectedKeys).includes(item.key)
            );

            if (allChildrenSelected) {
                visibilityGroupsSelectedKeys[groupIndex.toString()] = { checked: true };
            }
        });

        return visibilityGroupsSelectedKeys;
    });

    const targetGroupsOnSelectionChange = (e) => {
        setTargetGroupsSelected(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(e.value),
            sendEmailToTargetGroups,
            onlyPostGroups(visibilityGroupsSelected),
            sendEmailToVisibilityGroups
        );
    };

    const sendEmailToTargetGroupsOnChange = (e) => {
        setSendEmailToTargetGroups(e.value)

        if (!onChange) return;

        onChange(
            onlyPostGroups(targetGroupsSelected),
            e.value,
            onlyPostGroups(visibilityGroupsSelected),
            sendEmailToVisibilityGroups
        );
    };

    const visibilityGroupsOnSelectionChange = (e) => {
        setVisibilityGroupsSelected(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(targetGroupsSelected),
            sendEmailToTargetGroups,
            onlyPostGroups(e.value),
            sendEmailToVisibilityGroups
        );
    };

    const sendEmailToVisibilityGroupsOnChange = (e) => {
        setSendEmailToVisibilityGroups(e.value);

        if (!onChange) return;

        onChange(
            onlyPostGroups(targetGroupsSelected),
            sendEmailToTargetGroups,
            onlyPostGroups(visibilityGroupsSelected),
            e.value
        );
    };

    const getInitialTargetGroupsExpandedKeys = () => {
        const initialExpandedKeys = {};

        treeNodes.forEach((group, groupIndex) => {
            const hasSelectedChild = group.children.some(item =>
                Object.keys(targetGroupsSelected).includes(item.key)
            );

            if (hasSelectedChild) {
                initialExpandedKeys[groupIndex.toString()] = true;
            }
        });

        return initialExpandedKeys;
    }

    const getInitialVisibilityGroupsExpandedKeys = () => {
        const initialExpandedKeys = {};

        treeNodes.forEach((group, groupIndex) => {
            const hasSelectedChild = group.children.some(item =>
                Object.keys(visibilityGroupsSelected).includes(item.key)
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

    const targetGroupsHandleToggle = (e) => {
        if (e.originalEvent === null && Object.keys(e.value).length === 0) {
            setTargetGroupsExpandedKeys(getInitialTargetGroupsExpandedKeys());
            return;
        }

        setTargetGroupsExpandedKeys(e.value);
    };

    const visibilityGroupsHandleToggle = (e) => {
        if (e.originalEvent === null && Object.keys(e.value).length === 0) {
            setVisibilityGroupsExpandedKeys(getInitialVisibilityGroupsExpandedKeys());
            return;
        }

        setVisibilityGroupsExpandedKeys(e.value);
    };

    const onlyPostGroups = (selectedGroups) => {
        const groupTags = options.map(option => option.uid);
        return Object.keys(selectedGroups).filter(uid => groupTags.includes(uid));
    }

    return (
        <div>
            {label && <h2 htmlFor={targetGroupsName}>{label}</h2>}

            <div class="post-groups-selector">
                <Button type="button" label="Select" onClick={() => setVisible(true)} />
                <p class="post-groups-count">{onlyPostGroups(targetGroupsSelected).length + onlyPostGroups(visibilityGroupsSelected).length} group(s) selected</p>
            </div>

            <Dialog 
                header="Post Groups" 
                visible={visible} 
                onHide={() => setVisible(false)}
                loading={true}
                className="bcc-group-selector__dialog"
            >
                <div id="target-groups-selector">
                    { !isSettingsPage && ( <h3>Target groups</h3> ) }

                    <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                        <Button type="button" icon="dashicons dashicons-plus" label="Expand All" onClick={() => setTargetGroupsExpandedKeys(getAllKeys())} />
                        <Button type="button" icon="dashicons dashicons-minus" label="Collapse All" onClick={() => setTargetGroupsExpandedKeys({})} />
                    </div>

                    <Tree 
                        value={treeNodes}
                        filter
                        filterPlaceholder="Search group ..."
                        filterDelay={100}
                        filterMode="lenient"
                        selectionMode="checkbox"
                        selectionKeys={targetGroupsSelected}
                        onSelectionChange={targetGroupsOnSelectionChange}
                        expandedKeys={targetGroupsExpandedKeys}
                        onToggle={targetGroupsHandleToggle}
                        emptyMessage="No groups match your search."
                    />

                    { !isSettingsPage && ( <div className="flex flex-wrap gap-2 mb-4 items-center">
                        <h4>Send Email:</h4>
                        <SelectButton value={sendEmailToTargetGroups} onChange={sendEmailToTargetGroupsOnChange} options={sendEmailOptions} />
                    </div> ) }
                </div>

                { !isSettingsPage && (
                    <div id="visibility-groups-selector">
                        <h3>Visibility groups</h3>
                        <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                            <Button type="button" icon="dashicons dashicons-plus" label="Expand All" onClick={() => setVisibilityGroupsExpandedKeys(getAllKeys())} />
                            <Button type="button" icon="dashicons dashicons-minus" label="Collapse All" onClick={() => setVisibilityGroupsExpandedKeys({})} />
                        </div>

                        <Tree 
                            value={treeNodes}
                            filter
                            filterPlaceholder="Search group ..."
                            filterDelay={100}
                            filterMode="lenient"
                            selectionMode="checkbox"
                            selectionKeys={visibilityGroupsSelected}
                            onSelectionChange={visibilityGroupsOnSelectionChange}
                            expandedKeys={visibilityGroupsExpandedKeys}
                            onToggle={visibilityGroupsHandleToggle}
                            emptyMessage="No groups match your search."
                        />

                        <div className="flex flex-wrap gap-2 mb-4 items-center">
                            <h4>Send Email:</h4>
                            <SelectButton value={sendEmailToVisibilityGroups} onChange={sendEmailToVisibilityGroupsOnChange} options={sendEmailOptions} />
                        </div>
                    </div>
                ) }
            </Dialog>

            <input type="hidden" name={targetGroupsName} value={onlyPostGroups(targetGroupsSelected).join(',')} />

            { !isSettingsPage && (
                <div>
                    <input type="hidden" name={sendEmailToTargetGroupsName} value={sendEmailToTargetGroups} />
                    <input type="hidden" name={visibilityGroupsName} value={onlyPostGroups(visibilityGroupsSelected).join(',')} />
                    <input type="hidden" name={sendEmailToVisibilityGroupsName} value={sendEmailToVisibilityGroups} />
                </div>
            ) }
        </div>
    );
};

export default GroupSelector;