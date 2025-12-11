import { useState } from 'react';
import { Chips } from 'primereact/chips';

const TagInput = ({ name, value }) => {
    const [tags, setTags] = useState(value.split(',') || []);

    const handleChange = (e) => {
        setTags(e.value);

        if (name === 'bcc_site_group_tags') {
            window.dispatchEvent(new CustomEvent('bcc:tagsChanged', {
                detail: { value: e.value }
            }));
        }
    };

    return (
        <div>
            <Chips
                value={tags}
                onChange={handleChange}
                style={{ width: '100%' }}
                separator=","
            />
            <input type="hidden" name={name} value={tags.join(',')} />
        </div>
    );
};

export default TagInput;