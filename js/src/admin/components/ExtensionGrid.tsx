import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import { cardOffers, sortForGrid } from '../runState';
import type { Installed } from '../types';

export interface ExtensionGridAttrs extends ComponentAttrs {
  installed: Installed[];
  starting: boolean;
  updateOne: (e: Installed) => void;
  removeOne: (e: Installed) => void;
}

/**
 * The Installed tab's cards: one per extension, what is true of it on the
 * left of its foot and what can be done with it on the right.
 */
export default class ExtensionGrid extends Component<ExtensionGridAttrs> {
  /** Anything with a newer version first — that is what somebody came to see. */
  sorted(): Installed[] {
    return sortForGrid(this.attrs.installed);
  }

  cardTop(e: Installed) {
    return [
      <div
        className="Millwright-icon"
        style={{ background: e.icon?.backgroundColor || 'var(--primary-color)', color: e.icon?.color || 'var(--button-primary-color)' }}
      >
        {e.library ? <i className="fas fa-box" /> : e.icon?.name ? <i className={e.icon.name} /> : e.name.charAt(0)}
      </div>,
      <div className="Millwright-cardId">
        <div className="Millwright-name">{e.name}</div>
        <div className="Millwright-pkg">{e.library ? app.translator.trans('ernestdefoe-millwright.admin.extensions.library') : e.package}</div>
      </div>,
    ];
  }

  /**
   * Always two groups: what is TRUE about this extension on the
   * left, what you can DO with it on the right. The foot is
   * space-between, so loose chips get pushed to opposite ends of
   * the card and read as unrelated — which is what "enabled" and
   * "local checkout" did.
   */
  view() {
    return (
      <div className="Millwright-grid">
        {this.sorted().map((e) => (
          <div className={'Millwright-card' + (cardOffers(e).highlight ? ' Millwright-card--update' : '')} key={e.id}>
            {/* The extension's own settings page, the way the Extensions list opens it. A library has none. */}
            {e.library ? (
              <div className="Millwright-cardTop" title={app.translator.trans('ernestdefoe-millwright.admin.extensions.library_why', {}, true)}>
                {this.cardTop(e)}
              </div>
            ) : (
              <Link
                className="Millwright-cardTop"
                href={app.route('extension', { id: e.id })}
                title={app.translator.trans('ernestdefoe-millwright.admin.extensions.open_settings', { name: e.name }, true)}
              >
                {this.cardTop(e)}
              </Link>
            )}

            {/*
             * One place for the version: the pair when there is a newer one,
             * else the version installed. The border and the Update button
             * already say an update is waiting — a badge saying it a third
             * time only crowded the package name out (ClaudiusH, 2026-10-13).
             */}
            <div className="Millwright-meta">
              <span>
                {e.update
                  ? `${e.update.from} → ${e.update.to}`
                  : e.version || app.translator.trans('ernestdefoe-millwright.admin.extensions.version_unknown')}
              </span>
            </div>

            <div className="Millwright-foot">
              {this.cardTags(e)}

              {this.cardActions(e)}
            </div>
          </div>
        ))}
      </div>
    );
  }

  /** What is true of this extension: enabled or not, a library, a local checkout. */
  cardTags(e: Installed) {
    return (
      <span className="Millwright-tags">
        {e.library ? (
          <span
            className="Millwright-tag Millwright-tag--muted"
            title={app.translator.trans('ernestdefoe-millwright.admin.extensions.library_why', {}, true)}
          >
            {app.translator.trans('ernestdefoe-millwright.admin.extensions.library')}
          </span>
        ) : (
          <span className={'Millwright-tag' + (e.enabled ? ' Millwright-tag--ok' : '')}>
            {e.enabled
              ? app.translator.trans('ernestdefoe-millwright.admin.extensions.enabled')
              : app.translator.trans('ernestdefoe-millwright.admin.extensions.disabled')}
          </span>
        )}
        {e.pathInstall ? (
          <span
            className="Millwright-tag Millwright-tag--muted"
            title={app.translator.trans('ernestdefoe-millwright.admin.extensions.path_install_why', {}, true)}
          >
            {app.translator.trans('ernestdefoe-millwright.admin.extensions.path_install')}
          </span>
        ) : null}
      </span>
    );
  }

  /** What can be done with it: read the release, update it, remove it. */
  cardActions(e: Installed) {
    return (
      <span className="Millwright-actions">
        {/*
         * What the update gives you, from the release on GitHub: not
         * every author posts changelogs anywhere else.
         */}
        {e.update?.notes ? (
          <Button
            className="Button Button--link Button--sm Millwright-notes"
            onclick={() =>
              app.modal.show(() => import('./ReleaseNotesModal'), {
                name: e.name,
                package: e.package,
                from: e.update!.from,
                to: e.update!.to,
              })
            }
          >
            {app.translator.trans('ernestdefoe-millwright.admin.extensions.learn_more')}
          </Button>
        ) : null}
        {cardOffers(e).update ? (
          <Button
            className="Button Button--primary Button--sm"
            disabled={this.attrs.starting}
            onclick={() => this.attrs.updateOne(e)}
          >
            {app.translator.trans('ernestdefoe-millwright.admin.extensions.update')}
          </Button>
        ) : null}
        {cardOffers(e).remove ? (
          <Button className="Button Button--sm" disabled={this.attrs.starting} onclick={() => this.attrs.removeOne(e)}>
            {app.translator.trans('ernestdefoe-millwright.admin.extensions.remove')}
          </Button>
        ) : null}
      </span>
    );
  }
}
