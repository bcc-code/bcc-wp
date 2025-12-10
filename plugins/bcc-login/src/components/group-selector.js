// src/GroupSelectionEditor.js
import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { SelectButton } from 'primereact/selectbutton';
import { Tree } from 'primereact/tree';
import { __ } from '@wordpress/i18n';

const GroupSelector = ({ tags, options, label, targetGroupsName, targetGroupsValue, sendEmailToTargetGroupsValue, visibilityGroupsName, visibilityGroupsValue, sendEmailToVisibilityGroupsValue, isSettingPostGroups, onChange }) => {
    const [visible, setVisible] = useState(false);

    const sendEmailOptions = [
        { label: 'Yes', value: true },
        { label: 'No', value: false }
    ];
    const [sendEmailToTargetGroups, setSendEmailToTargetGroups] = useState(() => {
        return sendEmailToTargetGroupsValue ? sendEmailToTargetGroupsValue : sendEmailOptions[0]
    });
    const [sendEmailToVisibilityGroups, setSendEmailToVisibilityGroups] = useState(() => {
        return sendEmailToVisibilityGroupsValue ? sendEmailToVisibilityGroupsValue : sendEmailOptions[1]
    });

    const getGroupsByTag = (selectedTags) => {
        const optionGroups = [];

        // Add items to groups based on tags
        selectedTags.forEach(tag => {
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
    };

    const getInitialTargetGroupsSelected = () => {
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
    };

    const getInitialVisibilityGroupsSelected = () => {
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
    };

    const [treeNodes, setTreeNodes] = useState(getGroupsByTag(tags));

    // Rebuild treeNodes when tags change via external Tag Input component
    useEffect(() => {
        const rebuild = (currentTags) => {
            const groupsByTag = getGroupsByTag(currentTags);
            setTreeNodes(groupsByTag);

            // Keep selected groups belonging to current tags
            const currentGroupChildrenKeys = groupsByTag.flatMap(group => group.children.map(child => child.key));
            
            const keepSelectedGroups = (selectedGroups) => {
                const newSelectedGroups = {};
                Object.keys(selectedGroups).forEach(key => {
                    if (currentGroupChildrenKeys.includes(key)) {
                        newSelectedGroups[key] = selectedGroups[key];
                    }
                });
                return newSelectedGroups;
            };

            setTargetGroupsSelected(keepSelectedGroups(targetGroupsSelected));
            setVisibilityGroupsSelected(keepSelectedGroups(visibilityGroupsSelected));

            // After tree rebuild, restore expanded keys based on current selections
            setTargetGroupsExpandedKeys(getInitialTargetGroupsExpandedKeys());
            setVisibilityGroupsExpandedKeys(getInitialVisibilityGroupsExpandedKeys());
        };

        const handler = (ev) => {
            const { value } = ev.detail || {};

            // Accept both array and comma-separated string
            const nextTags = Array.isArray(value)
                ? value
                : (typeof value === 'string'
                    ? value.split(',').map(t => t.trim()).filter(Boolean) 
                    : tags
                );

            rebuild(nextTags);
        };

        window.addEventListener('bcc:tagsChanged', handler);
        return () => window.removeEventListener('bcc:tagsChanged', handler);
    }, [options, tags]);

    const [targetGroupsExpandedKeys, setTargetGroupsExpandedKeys] = useState({});
    const [visibilityGroupsExpandedKeys, setVisibilityGroupsExpandedKeys] = useState({});

    const [targetGroupsSelected, setTargetGroupsSelected] = useState(getInitialTargetGroupsSelected());
    const [visibilityGroupsSelected, setVisibilityGroupsSelected] = useState(getInitialVisibilityGroupsSelected());

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
    };

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
    };

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
    };

    return (
        <div>
            {label && <h2 htmlFor={targetGroupsName}>{label}</h2>}

            <div class="post-groups-selector">
                <Button type="button" label={__('Select', 'bcc-login')} onClick={() => setVisible(true)} />
                <p class="post-groups-count">{onlyPostGroups(targetGroupsSelected).length + onlyPostGroups(visibilityGroupsSelected).length} {__('group(s) selected', 'bcc-login')}</p>
            </div>

            <Dialog 
                header={__('Post Groups', 'bcc-login')} 
                visible={visible} 
                onHide={() => setVisible(false)}
                loading={true}
                className="bcc-group-selector__dialog"
            >
                <div id="target-groups-selector" class="group-selector-section">
                    { isSettingPostGroups && ( <h3>{__('Requires action:', 'bcc-login')}</h3> ) }

                    <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                        <Button type="button" icon="dashicons dashicons-plus" label={__('Expand All', 'bcc-login')} onClick={() => setTargetGroupsExpandedKeys(getAllKeys())} />
                        <Button type="button" icon="dashicons dashicons-minus" label={__('Collapse All', 'bcc-login')} onClick={() => setTargetGroupsExpandedKeys({})} />
                    </div>

                    <Tree 
                        value={treeNodes}
                        filter
                        filterPlaceholder={__('Search group ...', 'bcc-login')}
                        filterDelay={100}
                        filterMode="lenient"
                        selectionMode="checkbox"
                        selectionKeys={targetGroupsSelected}
                        onSelectionChange={targetGroupsOnSelectionChange}
                        expandedKeys={targetGroupsExpandedKeys}
                        onToggle={targetGroupsHandleToggle}
                        emptyMessage={__('No groups match your search.', 'bcc-login')}
                    />

                    { isSettingPostGroups && ( <div className="flex flex-wrap gap-2 items-center">
                        <h4>{__('Send Email:', 'bcc-login')}</h4>
                        <SelectButton value={sendEmailToTargetGroups} onChange={sendEmailToTargetGroupsOnChange} options={sendEmailOptions} />
                    </div> ) }
                </div>

                { isSettingPostGroups && (
                    <div id="visibility-groups-selector" class="group-selector-section">
                        <h3>{__('Til informasjon', 'bcc-login')}</h3>
                        <div className="toggle-keys-buttons flex flex-wrap gap-2 mb-4 items-center">
                            <Button type="button" icon="dashicons dashicons-plus" label={__('Expand All', 'bcc-login')} onClick={() => setVisibilityGroupsExpandedKeys(getAllKeys())} />
                            <Button type="button" icon="dashicons dashicons-minus" label={__('Collapse All', 'bcc-login')} onClick={() => setVisibilityGroupsExpandedKeys({})} />
                        </div>

                        <Tree 
                            value={treeNodes}
                            filter
                            filterPlaceholder={__('Search group ...', 'bcc-login')}
                            filterDelay={100}
                            filterMode="lenient"
                            selectionMode="checkbox"
                            selectionKeys={visibilityGroupsSelected}
                            onSelectionChange={visibilityGroupsOnSelectionChange}
                            expandedKeys={visibilityGroupsExpandedKeys}
                            onToggle={visibilityGroupsHandleToggle}
                            emptyMessage={__('No groups match your search.', 'bcc-login')}
                        />

                        <div className="flex flex-wrap gap-2 items-center">
                            <h4>{__('Send Email:', 'bcc-login')}</h4>
                            <SelectButton value={sendEmailToVisibilityGroups} onChange={sendEmailToVisibilityGroupsOnChange} options={sendEmailOptions} />
                        </div>
                    </div>
                ) }
            </Dialog>

            <input type="hidden" name={targetGroupsName} value={onlyPostGroups(targetGroupsSelected).join(',')} />

            { isSettingPostGroups && (
                <div>
                    <input type="hidden" value={sendEmailToTargetGroups} />
                    <input type="hidden" name={visibilityGroupsName} value={onlyPostGroups(visibilityGroupsSelected).join(',')} />
                    <input type="hidden" value={sendEmailToVisibilityGroups} />
                </div>
            ) }
        </div>
    );
};

export default GroupSelector;