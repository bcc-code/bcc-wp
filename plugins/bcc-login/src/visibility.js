import { useState } from "react";
import { __, sprintf } from "@wordpress/i18n";
import { addFilter } from "@wordpress/hooks";
import {
  PanelBody,
  PanelRow,
  CheckboxControl,
  SearchControl,
} from "@wordpress/components";
import GroupSelector from './components/group-selector';
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
let filteredSiteGroups = siteGroups || [];

const visibilityOptions = [
  {
    value: levels.public,
    label: __("Public"),
  },
  {
    value: levels.subscriber,
    label: __("Logged In"),
  },
  {
    value: levels["bcc-login-member"],
    label: sprintf(__("%s Members"), localName),
  },
  {
    value: levels["public-only"],
    label: __("Not Logged In"),
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
  const [searchInput, setSearchInput] = useState("");

  if (!siteGroups || siteGroups.length === 0) {
    return;
  }

  // Sort by name
  siteGroups.sort((a, b) => {
    return a.name.localeCompare(b.name);
  });

  return (
    <div>
      {heading && <h2>{heading}</h2>}
      <SearchControl
        className="bcc-groups__search"
        value={searchInput}
        onChange={(e) => {
          setSearchInput(e);

          if (e == "") {
            filteredSiteGroups = siteGroups;
          } else {
            filteredSiteGroups = siteGroups.filter((group) =>
              group.name.toLowerCase().includes(e.toLowerCase())
            );
          }
        }}
      />
      {filteredSiteGroups.map((group) => (
        <CheckboxControl
          className="bcc-groups__checkbox"
          label={group.name}
          onChange={(event) => {
            const index = selectedGroups
              ? selectedGroups.indexOf(group.uid)
              : -1;
            const newGroups = JSON.parse(JSON.stringify(selectedGroups));
            if (index === -1) {
              newGroups.push(group.uid);
            } else {
              newGroups.splice(index, 1);
            }
            onUpdateGroup(newGroups);
          }}
          checked={selectedGroups ? selectedGroups.includes(group.uid) : false}
        />
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

registerPlugin("bcc-groups-2", {
  render: compose([
    withSelect((select) => {
      const { getEditedPostAttribute } = select("core/editor");
      const meta = getEditedPostAttribute("meta");

      return {
        targetGroupsValue: (meta.bcc_groups ?? []).join(","),
        sendEmailToTargetGroups: meta.bcc_groups_email,
        visibilityGroupsValue: (meta.bcc_visibility_groups ?? []).join(","),
        sendEmailToVisibilityGroups: meta.bcc_visibility_groups_email,
        options: window.siteGroups,
        tags: window.siteGroupTags,
      };
    }),
    withDispatch((dispatch) => {
      const { editPost } = dispatch("core/editor");
      return {
        onChange(targetGroupsValue, sendEmailToTargetGroups, visibilityGroupsValue, sendEmailToVisibilityGroups) {
          editPost({
            meta: {
              bcc_groups: targetGroupsValue,
              bcc_groups_email: sendEmailToTargetGroups,
              bcc_visibility_groups: visibilityGroupsValue,
              bcc_visibility_groups_email: sendEmailToVisibilityGroups,
            },
          });
        },
      };
    }),
    withInstanceId,
  ])((props) => (
    <PluginPostStatusInfo>
      <GroupSelector label={__("Post Groups")} {...props} />
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
            <PanelRow>
                <GroupSelector
                  label={__("Block Groups")}
                  tags={window.siteGroupTags}
                  options={window.siteGroups}
                  targetGroupsValue={(attributes.bccGroups ?? []).join(",")}
                  sendEmailToTargetGroups={attributes.bcc_groups_email}
                  visibilityGroupsValue={(attributes.bccVisibilityGroups ?? []).join(",")}
                  sendEmailToVisibilityGroups={attributes.bcc_visibility_groups_email}
                  onChange={(targetGroupsValue, sendEmailToTargetGroups, visibilityGroupsValue, sendEmailToVisibilityGroups) => {
                    setAttributes({
                      bccGroups: targetGroupsValue,
                      bccGroupsEmail: sendEmailToTargetGroups,
                      bccVisibilityGroups: visibilityGroupsValue,
                      bccSendEmailToVisibilityGroups: sendEmailToVisibilityGroups,
                    });
                  }}
                />
              </PanelRow>
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
