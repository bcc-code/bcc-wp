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
import SendNotifications from './components/send-notifications';
import { registerPlugin } from "@wordpress/plugins";
import { InspectorControls } from "@wordpress/block-editor";
import { PluginPostStatusInfo } from "@wordpress/editor";
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
        sendEmailToTargetGroupsValue: meta.bcc_groups_email ?? 'Yes',
        visibilityGroupsValue: (meta.bcc_visibility_groups ?? []).join(","),
        sendEmailToVisibilityGroupsValue: meta.bcc_visibility_groups_email ?? 'No',
        options: window.siteGroups,
        tags: window.siteGroupTags,
        isSettingPostGroups: true
      };
    }),
    withDispatch((dispatch) => {
      const { editPost } = dispatch("core/editor");
      return {
        onChange(targetGroupsValue, sendEmailToTargetGroupsValue, visibilityGroupsValue, sendEmailToVisibilityGroupsValue) {
          editPost({
            meta: {
              bcc_groups: targetGroupsValue,
              bcc_groups_email: sendEmailToTargetGroupsValue,
              bcc_visibility_groups: visibilityGroupsValue,
              bcc_visibility_groups_email: sendEmailToVisibilityGroupsValue,
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

registerPlugin("bcc-notifications", {
  render: compose([
    withSelect((select) => {
      const { getCurrentPostId, getEditedPostAttribute, isEditedPostDirty, isAutosavingPost } = select("core/editor");
      const meta = getEditedPostAttribute('meta');

      const targetGroupsCount = Array.isArray(meta.bcc_groups)
        && meta.bcc_groups_email == 'Yes'
          ? meta.bcc_groups.length : 0;
      
      const visibilityGroupsCount = Array.isArray(meta.bcc_visibility_groups)
        && meta.bcc_visibility_groups_email == 'Yes'
          ? meta.bcc_visibility_groups.length : 0;

      return {
        postId: getCurrentPostId(),
        status: getEditedPostAttribute('status'),
        targetGroupsCount: targetGroupsCount,
        visibilityGroupsCount: visibilityGroupsCount,
        isNotificationDryRun: window.bccLoginNotificationDryRun,
        isDirty: isEditedPostDirty(),
        isAutoSaving: isAutosavingPost()
      };
    }),
    withInstanceId,
  ])((props) => (
    <PluginPostStatusInfo>
      <SendNotifications label={__("Send ut varsler")} {...props} />
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
                  onChange={(targetGroupsValue) => {
                    setAttributes({
                      bccGroups: targetGroupsValue,
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
