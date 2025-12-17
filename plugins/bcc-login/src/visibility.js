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
import SentNotificationsList from './components/sent-notifications-list';
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
    label: __("Public", "bcc-login"),
  },
  {
    value: levels.subscriber,
    label: __("Logged In", "bcc-login"),
  },
  {
    value: levels["bcc-login-member"],
    /* translators: %s is the local name of the member organization (e.g., "BCC") */
    label: sprintf(__("%s Members", "bcc-login"), localName),
  },
  {
    value: levels["public-only"],
    label: __("Not Logged In", "bcc-login"),
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
          getEditedPostAttribute("meta")?.bcc_login_visibility || defaultLevel,
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
      <VisibilityOptions heading={__("Post Audience", "bcc-login")} {...props} />
    </PluginPostStatusInfo>
  )),
});

registerPlugin("bcc-groups-2", {
  render: compose([
    withSelect((select) => {
      const { getEditedPostAttribute } = select("core/editor");
      const meta = getEditedPostAttribute("meta");

      return {
        groupsValue: (meta?.bcc_groups ?? []).join(","),
        sendEmailToTargetGroupsValue: meta?.bcc_groups_email ?? true,
        visibilityGroupsValue: (meta?.bcc_visibility_groups ?? []).join(","),
        sendEmailToVisibilityGroupsValue: meta?.bcc_visibility_groups_email ?? false,
        options: window.siteGroups,
        tags: window.siteGroupTags,
        isSettingPostGroups: true
      };
    }),
    withDispatch((dispatch) => {
      const { editPost } = dispatch("core/editor");
      return {
        onChange(targetGroups, sendEmailToTargetGroups, visibilityGroups, sendEmailToVisibilityGroups) {
          editPost({
            meta: {
              bcc_groups: targetGroups,
              bcc_groups_email: sendEmailToTargetGroups,
              bcc_visibility_groups: visibilityGroups,
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

registerPlugin("bcc-notifications", {
  render: compose([
    withSelect((select) => {
      const { getCurrentPostId, getCurrentPostType, getEditedPostAttribute, isEditedPostDirty, isAutosavingPost } = select("core/editor");
      const meta = getEditedPostAttribute('meta');

      const targetGroupsCount = Array.isArray(meta?.bcc_groups) && meta?.bcc_groups_email
          ? meta.bcc_groups.length : 0;

      const visibilityGroupsCount = Array.isArray(meta?.bcc_visibility_groups) && meta?.bcc_visibility_groups_email
          ? meta.bcc_visibility_groups.length : 0;

      const postType = getCurrentPostType();
      const allowedTypes = Array.isArray(window.bccLoginNotificationPostTypes) ? window.bccLoginNotificationPostTypes : [];

      const sentNotifications = meta?.sent_notifications?.map(notification => {
        return {
          date: (new Date(notification.date)).toLocaleString(),
          no_of_groups: notification.notification_groups.length
        };
      }) ?? [];

      return {
        postId: getCurrentPostId(),
        postType,
        status: getEditedPostAttribute('status'),
        targetGroupsCount,
        visibilityGroupsCount,
        isNotificationDryRun: window.bccLoginNotificationDryRun,
        isDirty: isEditedPostDirty(),
        isAutoSaving: isAutosavingPost(),
        isAllowedPostType: allowedTypes.includes(postType),
        sentNotifications: sentNotifications
      };
    }),
    withInstanceId,
  ])((props) => (
    props.isAllowedPostType ? (
      <PluginPostStatusInfo className="bcc-login-notifications-plugin-post-status-info">
        <SendNotifications label={__("Send notifications", "bcc-login")} {...props} />
        <SentNotificationsList {...props} />
      </PluginPostStatusInfo>
    ) : null
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
                heading={__("Block Audience", "bcc-login")}
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
                  label={__("Block Groups", "bcc-login")}
                  tags={window.siteGroupTags}
                  options={window.siteGroups}
                  groupsValue={(attributes.bccGroups ?? []).join(",")}
                  onChange={(groupsValue) => {
                    setAttributes({
                      bccGroups: groupsValue,
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
