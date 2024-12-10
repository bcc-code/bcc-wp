// src/GroupSelectionEditor.js
import React, { useState, useEffect } from 'react';
import { MultiSelect } from 'primereact/multiselect';


const GroupSelector = ({ tags, options, name, label, value, readonly, onChange }) => {


    const selectedGroupUids = value ? value.split(',') : [];
    const optionGroups = [];
    
    // Add selected items to the top group
    if (selectedGroupUids.length > 0) {
        optionGroups.push(
            {
                tag: 'Selected',
                items: options.filter(option => selectedGroupUids.indexOf(option.uid) != -1).sort((a, b) => a.name > b.name ? 1 : -1)
            }
        );
    }

    // Add other items to groups based on tags (in order of tags)
    tags.forEach(tag => {
        const items = options.filter(option => selectedGroupUids.indexOf(option.uid) == -1 && option.tags && option.tags.indexOf(tag) != -1).sort((a, b) => a.name > b.name ? 1 : -1)
        if (items.length > 0) {
            optionGroups.push({
                tag: tag,
                items: items
            });
        }
    });
    

    const [selectedGroups, setSelectedGroups] = useState(selectedGroupUids);
    const [groups, setGroups] = useState(optionGroups);

    return (
        <div>
            {label && <h2 htmlFor={name}>{label}</h2>}
            <MultiSelect
                id={name}
                name={name+"_selector"}
                value={selectedGroups}
                options={optionGroups}
                useOptionAsValue={false}
                optionLabel="name"
                optionValue="uid"
                optionGroupLabel="tag"
                optionGroupChildren="items"
                onChange={(e) => { setSelectedGroups(e.value); if (onChange) { onChange(e.value); } }}
                filter
                maxSelectedLabels={3}
                placeholder="Select Groups"
                disabled={readonly}
            />
            {/* Hidden input for form submission */}
            <input type="hidden" name={name} value={(selectedGroups || []).join(',')} />
            <style>
                {`
                    .p-multiselect-panel {
                        max-width: 300px;
                    }
                    .p-multiselect-label, .p-multiselect-item {
                        font-size: 13px;
                        text-wrap-mode: wrap;
                    }
                    .p-multiselect {
                        font-size: 13px;
                        border: 1px solid #ced4da;
                    }
                `}
            </style>
        </div>
    );
};

export default GroupSelector;
