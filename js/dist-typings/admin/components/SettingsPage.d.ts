/// <reference types="mithril" />
import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
export default class SettingsPage extends ExtensionPage<ExtensionPageAttrs> {
    content(): JSX.Element;
    triggerBackfill(mode: string): void;
}
