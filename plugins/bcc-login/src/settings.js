// src/config.js
import { createRoot } from 'react-dom/client';
import TagInput from './components/tag-input';
import GroupSelector from './components/group-selector';

export function renderTagInput(containerId, props) {
    const container = document.getElementById(containerId);
    const root = createRoot(container);
    root.render(<TagInput {...props} />);
}

export function renderGroupSelector(containerId, props) {
    const container = document.getElementById(containerId);
    const root = createRoot(container);
    root.render(<GroupSelector {...props} />);
}

// Ensure the function is called to include it in the output
if (typeof window !== 'undefined') {
    window.renderGroupSelector = renderGroupSelector;
    window.renderTagInput = renderTagInput;
}