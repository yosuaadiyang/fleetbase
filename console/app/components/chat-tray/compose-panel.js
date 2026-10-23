import BaseComposePanel from '@fleetbase/ember-ui/components/chat-tray/compose-panel';

// The owned colocated template needs its own class, rather than the addon's
// generated default re-export, so Ember can associate it unambiguously.
export default class ChatComposePanelComponent extends BaseComposePanel {}
