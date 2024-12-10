// src/config.js
import React from 'react';
import { createRoot } from 'react-dom/client';
import GroupSelector from './components/group-selector';
//
export function renderGroupSelector(containerId, props) {
    const container = document.getElementById(containerId);
    const root = createRoot(container);
    root.render(<GroupSelector {...props} />);
}

// Ensure the function is called to include it in the output
if (typeof window !== 'undefined') {
    window.renderGroupSelector = renderGroupSelector;
}