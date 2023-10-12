import { __, sprintf } from "@wordpress/i18n";
import { addFilter } from "@wordpress/hooks";
import { PanelBody } from "@wordpress/components";
import { registerPlugin } from "@wordpress/plugins";
import { InspectorControls } from "@wordpress/block-editor";
import { PluginPostStatusInfo } from "@wordpress/edit-post";
import { Fragment, cloneElement } from "@wordpress/element";
import { withSelect, withDispatch } from "@wordpress/data";
import {
  createHigherOrderComponent,
  withInstanceId,
  compose,
} from "@wordpress/compose";

const { defaultLevel, levels, localName } = window.bccLoginPostVisibility;

const visibilityOptions = [
  {
    value: levels.public,
    label: __("Public"),
  },
  {
    value: levels.subscriber,
    label: __("Authenticated Users"),
  },
  {
    value: levels["bcc-login-member"],
    label: sprintf(__("%s Members"), localName),
  },
];

function VisibilityOptions({
  heading,
  visibility,
  instanceId,
  onUpdateVisibility,
}) {
  return (
    <div>
      {heading && <h2>{heading}</h2>}
      {visibilityOptions.map(({ value, label }) => (
        <p key={value} className="bcc-login-visibility__choice">
          <input
            type="radio"
            name={`bcc-login-visibility__setting-${instanceId}`}
            value={value}
            onChange={(event) => {
              onUpdateVisibility(event.target.value);
            }}
            checked={visibility === value}
            id={`bcc-login-post-${value}-${instanceId}`}
            aria-describedby={`bcc-login-post-${value}-${instanceId}-description`}
            className="bcc-login-visibility__dialog-radio"
          />
          <label
            htmlFor={`bcc-login-post-${value}-${instanceId}`}
            className="bcc-login-visibility__dialog-label"
          >
            {label}
          </label>
        </p>
      ))}
    </div>
  );
}

function GroupsOptions({
  heading,
  siteGroups,
  selectedGroups,
  instanceId,
  onUpdateGroup,
}) {
  return (
    <div>
      {heading && <h2>{heading}</h2>}
      {siteGroups.map((group) => (
        <p key={group.uid} className="bcc-groups__choice">
          <input
            type="checkbox"
            name={`bcc-groups__setting-${instanceId}`}
            value={group.uid}
            onChange={(event) => {
              const index = selectedGroups.indexOf(group.uid);
              const newGroups = JSON.parse(JSON.stringify(selectedGroups));
              if (index === -1) {
                newGroups.push(group.uid);
              } else {
                newGroups.splice(index, 1);
              }
              onUpdateGroup(newGroups);
            }}
            checked={selectedGroups.includes(group.uid)}
            id={`bcc-login-post-${group.uid}-${instanceId}`}
            aria-describedby={`bcc-login-post-${group.uid}-${instanceId}-description`}
            className="bcc-groups__dialog-radio"
          />
          <label
            htmlFor={`bcc-login-post-${group.uid}-${instanceId}`}
            className="bcc-groups__dialog-label"
          >
            {group.name}
          </label>
        </p>
      ))}
    </div>
  );
}

registerPlugin("bcc-login-visibility", {
  render: compose([
    withSelect((select) => {
      const { getEditedPostAttribute } = select("core/editor");
      return {
        visibility:
          getEditedPostAttribute("meta").bcc_login_visibility || defaultLevel,
      };
    }),
    withDispatch((dispatch) => {
      const { editPost } = dispatch("core/editor");
      return {
        onUpdateVisibility(value) {
          editPost({
            meta: {
              bcc_login_visibility: Number(value) || defaultLevel,
            },
          });
        },
      };
    }),
    withInstanceId,
  ])((props) => (
    <PluginPostStatusInfo>
      <VisibilityOptions heading={__("Post Audience")} {...props} />
    </PluginPostStatusInfo>
  )),
});

registerPlugin("bcc-groups", {
  render: compose([
    withSelect((select) => {
      const { getEditedPostAttribute } = select("core/editor");
      const meta = getEditedPostAttribute("meta");
      return {
        selectedGroups: meta.bcc_groups,
        siteGroups: window.siteGroups,
      };
    }),
    withDispatch((dispatch) => {
      const { editPost } = dispatch("core/editor");
      return {
        onUpdateGroup(value) {
          editPost({
            meta: {
              bcc_groups: value,
            },
          });
        },
      };
    }),
    withInstanceId,
  ])((props) => (
    <PluginPostStatusInfo>
      <GroupsOptions heading={__("Post Groups")} {...props} />
    </PluginPostStatusInfo>
  )),
});

addFilter(
  "editor.BlockEdit",
  "bcc-login/visibility",
  createHigherOrderComponent((BlockEdit) => {
    return withInstanceId((props) => {
      const { attributes, setAttributes } = props;

      return (
        <Fragment>
          <InspectorControls>
            <PanelBody>
              <VisibilityOptions
                heading={__("Block Audience")}
                visibility={attributes.bccLoginVisibility || defaultLevel}
                onUpdateVisibility={(value) => {
                  setAttributes({
                    bccLoginVisibility: Number(value) || undefined,
                  });
                }}
                {...props}
              />
            </PanelBody>
          </InspectorControls>
          <BlockEdit {...props} />
        </Fragment>
      );
    });
  }, "withInspectorControl")
);

addFilter("blocks.registerBlockType", "bcc-login/visibility", (settings) => ({
  ...settings,
  attributes: {
    ...settings.attributes,
    bccLoginVisibility: {
      type: "number",
      default: defaultLevel,
    },
  },
}));

addFilter(
  "blocks.getSaveContent",
  "bcc-login/visibility",
  (element, block, attributes) =>
    cloneElement(
      element,
      {},
      cloneElement(element.props.children, {
        bccLoginVisibility: attributes.bccLoginVisibility,
      })
    )
);
