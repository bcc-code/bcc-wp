import { __, sprintf } from '@wordpress/i18n'
import { addFilter } from '@wordpress/hooks'
import { PanelBody, CheckboxControl, RadioControl } from '@wordpress/components'
import { registerPlugin } from '@wordpress/plugins'
import { InspectorControls } from '@wordpress/block-editor'
import { PluginPostStatusInfo } from '@wordpress/edit-post'
import { Fragment, cloneElement, useState } from '@wordpress/element'
import { withSelect, withDispatch } from '@wordpress/data'
import { createHigherOrderComponent, withInstanceId, compose } from '@wordpress/compose'

const { defaultLevel, levels, localName, targetAudience } = window.bccLoginPostVisibility

const visibilityOptions = [
  {
    value: levels.public,
    label: __('Public'),
  },
  {
    value: levels.subscriber,
    label: __('Authenticated Users'),
  },
  {
    value: levels['bcc-login-member'],
    label: sprintf(__('%s Members'), localName),
  }
]

registerPlugin('bcc-login-visibility', {
  render: compose([
    withSelect(select => {
      const { getEditedPostAttribute } = select('core/editor')
      return {
        visibility: getEditedPostAttribute('meta').bcc_login_visibility || defaultLevel,
        targetAudienceVisibility: getEditedPostAttribute('meta').bcc_login_target_audience_visibility
      }
    }),
    withDispatch(dispatch => {
      const { editPost } = dispatch('core/editor')
      return {
        onUpdateVisibility(value) {
          editPost({
            meta: {
              bcc_login_visibility: Number(value) || defaultLevel
            }
          })
        },
        onUpdateTargetVisibility(values) {    
          editPost({
            meta: {
              bcc_login_target_audience_visibility: values
            }
          })
        }
      }
    }),
    withInstanceId
  ])((props) => (
    <PluginPostStatusInfo>
      <div>
        <div class="membership-audience">
          <h2>{__('Post Membership Audience')}</h2>
          <MembershipVisibility {...props} />
        </div>
        <div class="target-audience">
          <h2>{__('Post Target Audience')}</h2>
          <TargetVisibility {...props} />
        </div>
      </div>
    </PluginPostStatusInfo>
  ))
})

addFilter(
  'editor.BlockEdit',
  'bcc-login/visibility',
  createHigherOrderComponent((BlockEdit) => {
    return withInstanceId((props) => {
      const { attributes, setAttributes } = props
      return (
        <Fragment>
          <InspectorControls>
            <PanelBody>
              <div>
                <div class="membership-audience">
                  <h2>{__('Block Membership Audience')}</h2>
                  <MembershipVisibility
                    visibility={attributes.bccLoginVisibility || defaultLevel}
                    onUpdateVisibility={(value) => {
                      setAttributes({ bccLoginVisibility: Number(value) || undefined })
                    }}
                    {...props}
                  />
                </div>
                <div class="target-audience">
                  <h2>{__('Block Target Audience')}</h2>
                  <TargetVisibility
                    targetAudienceVisibility={attributes.bccLoginTargetVisibility || new Array()}
                    onUpdateTargetVisibility={(values) => {
                      let updatedVisibility = new Array()
                      values.forEach(value => {
                        updatedVisibility.push(value)
                      })
                      setAttributes({ bccLoginTargetVisibility: updatedVisibility || undefined })
                    }}
                    {...props}
                  />
                </div>
              </div>
            </PanelBody>
          </InspectorControls>
          <BlockEdit {...props} />
        </Fragment>
      )
    })
  }, 'withInspectorControl')
)

const MembershipVisibility = ({
  visibility,
  instanceId,
  onUpdateVisibility
}) => {
  return (
    <div class="membership-visibility">
      <RadioControl
        name={ `bcc-login-visibility__setting-${instanceId}` }
        options={ visibilityOptions }
        selected={ visibility }
        onChange={ (value) => onUpdateVisibility(value) }
      />
    </div>
  )
}

const TargetVisibility = ({
  targetAudienceVisibility,
  instanceId,
  onUpdateTargetVisibility
}) => {
  const [checked, setChecked] = useState(false)
  let postTargetAudience = [...targetAudienceVisibility]
  return (
    targetAudience.map(({ value, label }) => (
      <CheckboxControl
        label={ label }
        name={ `bcc-login-role-visibility__setting-${instanceId}` }
        value={ value }
        checked={ postTargetAudience.includes(value) }
        onChange={ () => {
          const index = postTargetAudience.indexOf(value)
          if (index === -1) {
            postTargetAudience.push(value)
          } else {
            postTargetAudience.splice(index, 1)
          }
          onUpdateTargetVisibility(postTargetAudience)
          setChecked(!checked)
        }}
      />
    ))
  )
}

addFilter(
  'blocks.registerBlockType',
  'bcc-login/visibility',
  (settings) => ({
    ...settings,
    attributes: {
      ...settings.attributes,
      bccLoginVisibility: {
        type: 'number',
        default: defaultLevel
      },
      bccLoginTargetVisibility: {
        type: 'array'
      }
    }
  })
)

addFilter(
  'blocks.getSaveContent',
  'bcc-login/visibility',
  (element, block, attributes) => cloneElement(
    element,
    {},
    cloneElement(
      element.props.children,
      {
        bccLoginVisibility: attributes.bccLoginVisibility,
        bccLoginTargetVisibility: attributes.bccLoginTargetVisibility
      }
    )
  )
)