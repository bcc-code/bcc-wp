// src/GroupSelectionEditor.js
import React, { useState, useEffect } from 'react';
import { MultiSelect } from 'primereact/multiselect';


const GroupSelector = ({ tags, options, name, label, value, readonly }) => {

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
        const items = options.filter(option => option.tags && option.tags.indexOf(tag) != -1).sort((a, b) => a.name > b.name ? 1 : -1)
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
            {label && <label htmlFor={name}>{label}</label>}
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
                onChange={(e) => setSelectedGroups(e.value)}
                filter
                size="small"
                maxSelectedLabels={5}
                placeholder="Select Groups"
                disabled={readonly}
                className="w-full md:w-20rem"
            />
            {/* Hidden input for form submission */}
            <input type="hidden" name={name} value={(selectedGroups || []).join(',')} />
            
        </div>
    );
};

export default GroupSelector;
