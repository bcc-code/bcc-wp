import { useState } from 'react';
import { Chips } from 'primereact/chips';

const TagInput = ({ name, value }) => {
    const [tags, setTags] = useState(value.split(',') || []);

    return (
        <div>
            <Chips
                value={tags}
                onChange={(e) => setTags(e.value)}
                style={{ width: '100%' }}
                separator=","
            />
            <input type="hidden" name={name} value={tags.join(',')} />
        </div>
    );
};

export default TagInput;