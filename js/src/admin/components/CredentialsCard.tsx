import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type { ConfigPayload, StoredCredential as Stored } from '../types';

const KINDS: Record<string, { icon: string; host: string }> = {
  'github-oauth': { icon: 'fab fa-github', host: 'github.com' },
  'gitlab-token': { icon: 'fab fa-gitlab', host: 'gitlab.com' },
  bearer: { icon: 'fas fa-key', host: '' },
  'http-basic': { icon: 'fas fa-user-lock', host: '' },
};

/** Each change to Composer's settings has its own route under /millwright/config. */
export type ChangeMethod = 'POST' | 'PATCH' | 'DELETE';

export interface CredentialsCardAttrs extends ComponentAttrs {
  data: ConfigPayload;
  saving: boolean;
  send: (method: ChangeMethod, path: string, body: Record<string, unknown>, after?: () => void) => void;
}

/**
 * How Composer signs in to the hosts that need it, on the Composer settings
 * tab. A stored credential is never sent to the browser: this shows which
 * hosts have one, and entering a value replaces it.
 */
export default class CredentialsCard extends Component<CredentialsCardAttrs> {
  private authKind = 'github-oauth';
  private authHost = 'github.com';
  private authUser = '';
  private authSecret = '';
  private reveal = false;
  private confirmAuth: string | null = null;

  // ── credentials ─────────────────────────────────────────────────────────

  view() {
    const data = this.attrs.data;

    const a = data.auth || { stored: [] };
    const stored = a.stored || [];

    return (
      <section className="Millwright-src-card" id="mw-src-auth">
        <header className="Millwright-src-head">
          <div className="Millwright-src-headIcon">
            <i className="fas fa-key" aria-hidden="true" />
          </div>
          <div className="Millwright-src-headText">
            <h3>
              {app.translator.trans('ernestdefoe-millwright.admin.auth_config.title')} <span className="Millwright-src-count">{stored.length}</span>
            </h3>
            <p>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.help')}</p>
          </div>
        </header>

        {stored.length === 0 ? (
          <div className="Millwright-src-empty">
            <i className="fas fa-lock-open" aria-hidden="true" />
            <span>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.no_auth_methods_configured')}</span>
          </div>
        ) : (
          <ul className="Millwright-src-list">{stored.map((c) => this.authRow(c))}</ul>
        )}

        {this.addAuth(a.kinds || Object.keys(KINDS))}

        <div className="Millwright-src-callout Millwright-src-callout--lock">
          <i className="fas fa-shield-halved" aria-hidden="true" />
          <span>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.write_only')}</span>
        </div>
      </section>
    );
  }

  authRow(c: Stored) {
    const key = c.kind + '|' + c.host;
    const confirming = this.confirmAuth === key;
    const kind = KINDS[c.kind] || KINDS.bearer;

    return (
      <li className={'Millwright-src-item' + (confirming ? ' is-confirming' : '')} key={key}>
        <span className={'Millwright-src-logo Millwright-src-logo--' + (c.kind.split('-')[0] || 'other')}>
          <i className={kind.icon} aria-hidden="true" />
        </span>
        <span className="Millwright-src-itemText">
          <strong>{c.host}</strong>
          <span>
            <span className="Millwright-src-badge">{app.translator.trans('ernestdefoe-millwright.admin.auth_config.types.' + c.kind)}</span>
            <span className="Millwright-src-held">
              <i className="fas fa-lock" aria-hidden="true" />{' '}
              {c.detail === 'token set' ? app.translator.trans('ernestdefoe-millwright.admin.auth_config.token_stored') : c.detail}
            </span>
          </span>
        </span>

        {confirming ? this.confirmRemove(c) : this.rowActions(c, key)}
      </li>
    );
  }

  /** Asked in place before a credential is removed. */
  confirmRemove(c: Stored) {
    return (
      <span className="Millwright-src-confirm">
        <span>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.remove_q')}</span>
        <Button className="Button Button--sm" onclick={() => (this.confirmAuth = null)}>
          {app.translator.trans('ernestdefoe-millwright.admin.actions.cancel')}
        </Button>
        <Button
          className="Button Button--sm Button--danger"
          disabled={this.attrs.saving}
          onclick={() => this.attrs.send('DELETE', '/auth', { kind: c.kind, host: c.host }, () => (this.confirmAuth = null))}
        >
          {app.translator.trans('ernestdefoe-millwright.admin.actions.remove')}
        </Button>
      </span>
    );
  }

  /** Replace the stored value, or remove it after asking. */
  rowActions(c: Stored, key: string) {
    return (
      <span className="Millwright-src-actions">
        <Button
          className="Button Button--sm"
          disabled={this.attrs.saving}
          onclick={() => {
            // Replacing is entering a new value for the same host: the form
            // is filled in and the secret field is where the cursor lands.
            this.authKind = c.kind;
            this.authHost = c.host;
            this.authSecret = '';
            setTimeout(() => (document.getElementById('mw-src-secret') as HTMLInputElement | null)?.focus(), 0);
          }}
        >
          {app.translator.trans('ernestdefoe-millwright.admin.auth_config.edit_label')}
        </Button>
        <button
          className="Millwright-src-icon Millwright-src-icon--danger"
          disabled={this.attrs.saving}
          title={app.translator.trans('ernestdefoe-millwright.admin.actions.remove', {}, true)}
          aria-label={app.translator.trans('ernestdefoe-millwright.admin.auth_config.remove_button_label', { name: c.host }, true)}
          onclick={() => (this.confirmAuth = key)}
        >
          <i className="fas fa-trash-can" aria-hidden="true" />
        </button>
      </span>
    );
  }

  /**
   * Every credential kind links to the page that makes one. A field that asks
   * for a token without saying where tokens come from sends somebody to search
   * GitHub's settings for the right screen and the right scope.
   */
  tokenLink(): { href: string; label: string } | null {
    const host = this.authHost.trim() || KINDS[this.authKind]?.host || '';

    if (this.authKind === 'github-oauth') {
      // The note GitHub keeps beside the token, so its owner can tell later what it is for.
      const note = app.translator.trans(
        'ernestdefoe-millwright.admin.auth_config.github_token_note',
        { forum: app.forum.attribute<string>('title') },
        true
      );

      return {
        href: 'https://github.com/settings/tokens/new?scopes=repo&description=' + encodeURIComponent(note),
        label: app.translator.trans('ernestdefoe-millwright.admin.auth_config.create_github_token', {}, true),
      };
    }

    if (this.authKind === 'gitlab-token' && /^[a-z0-9.-]+$/i.test(host)) {
      return {
        href: 'https://' + host + '/-/user_settings/personal_access_tokens',
        label: app.translator.trans('ernestdefoe-millwright.admin.auth_config.create_gitlab_token', {}, true),
      };
    }

    return null;
  }

  addAuth(kinds: string[]) {
    const basic = this.authKind === 'http-basic';
    const link = this.tokenLink();

    return (
      <form
        className="Millwright-src-add"
        onsubmit={(e: Event) => {
          e.preventDefault();
          this.attrs.send('POST', '/auth', { kind: this.authKind, host: this.authHost, username: this.authUser, secret: this.authSecret }, () => {
            // Cleared from memory as soon as it has been sent.
            this.authSecret = '';
            this.authUser = '';
            this.reveal = false;
            this.authHost = KINDS[this.authKind]?.host || '';
          });
        }}
      >
        <div className="Millwright-src-addTitle">{app.translator.trans('ernestdefoe-millwright.admin.auth_config.add_label')}</div>

        {this.authKinds(kinds)}

        {this.authFields(basic)}

        {this.authFoot(basic, link)}
      </form>
    );
  }

  /** Which kind of credential: one choice per kind, as a radio group. */
  authKinds(kinds: string[]) {
    return (
      <div
        className="Millwright-src-choices Millwright-src-choices--four"
        role="radiogroup"
        aria-label={app.translator.trans('ernestdefoe-millwright.admin.auth_config.add_label', {}, true)}
      >
        {kinds.map((k) => (
          <button
            type="button"
            role="radio"
            aria-checked={this.authKind === k ? 'true' : 'false'}
            className={'Millwright-src-choice' + (this.authKind === k ? ' is-on' : '')}
            onclick={() => {
              const was = KINDS[this.authKind]?.host || '';
              this.authKind = k;
              // Fill the obvious host, but never overwrite one somebody typed.
              if (!this.authHost.trim() || this.authHost === was) this.authHost = KINDS[k]?.host || '';
            }}
          >
            <i className={(KINDS[k] || KINDS.bearer).icon} aria-hidden="true" />
            <span>
              <strong>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.types.' + k)}</strong>
              <small>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.type_help.' + k)}</small>
            </span>
          </button>
        ))}
      </div>
    );
  }

  /** The host, and the username (HTTP basic only) and secret for it. */
  authFields(basic: boolean) {
    return (
      <div className={'Millwright-src-grid' + (basic ? ' Millwright-src-grid--three' : '')}>
        <label className="Millwright-src-labelled">
          <span>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.fields.host')}</span>
          <input
            className="FormControl"
            placeholder={app.translator.trans('ernestdefoe-millwright.admin.auth_config.add_modal.host_placeholder', {}, true)}
            value={this.authHost}
            oninput={(e: InputEvent) => (this.authHost = (e.target as HTMLInputElement).value)}
          />
        </label>

        {basic ? (
          <label className="Millwright-src-labelled">
            <span>{app.translator.trans('ernestdefoe-millwright.admin.auth_config.fields.username')}</span>
            <input
              className="FormControl"
              autocomplete="off"
              placeholder={app.translator.trans('ernestdefoe-millwright.admin.auth_config.add_modal.username_placeholder', {}, true)}
              value={this.authUser}
              oninput={(e: InputEvent) => (this.authUser = (e.target as HTMLInputElement).value)}
            />
          </label>
        ) : null}

        {this.secretField(basic)}
      </div>
    );
  }

  /** The password or token, hidden unless asked to show it. */
  secretField(basic: boolean) {
    return (
      <label className="Millwright-src-labelled">
        <span>
          {basic
            ? app.translator.trans('ernestdefoe-millwright.admin.auth_config.fields.password')
            : app.translator.trans('ernestdefoe-millwright.admin.auth_config.fields.token')}
        </span>
        <span className="Millwright-src-secret">
          <input
            id="mw-src-secret"
            className="FormControl"
            type={this.reveal ? 'text' : 'password'}
            autocomplete="new-password"
            spellcheck={false}
            placeholder={app.translator.trans('ernestdefoe-millwright.admin.auth_config.add_modal.secret_placeholder', {}, true)}
            value={this.authSecret}
            oninput={(e: InputEvent) => (this.authSecret = (e.target as HTMLInputElement).value)}
          />
          <button
            type="button"
            className="Millwright-src-icon"
            aria-label={this.revealLabel()}
            title={this.revealLabel()}
            onclick={() => (this.reveal = !this.reveal)}
          >
            <i className={this.reveal ? 'fas fa-eye-slash' : 'fas fa-eye'} aria-hidden="true" />
          </button>
        </span>
      </label>
    );
  }

  /** Where to make a token, and the button that saves it. */
  authFoot(basic: boolean, link: { href: string; label: string } | null) {
    return (
      <div className="Millwright-src-addFoot">
        {link ? (
          <a className="Millwright-src-link" href={link.href} target="_blank" rel="noopener noreferrer">
            <i className="fas fa-arrow-up-right-from-square" aria-hidden="true" /> {link.label}
          </a>
        ) : (
          <span />
        )}
        <Button
          className="Button Button--primary"
          type="submit"
          icon="fas fa-lock"
          disabled={this.attrs.saving || !this.authHost.trim() || !this.authSecret || (basic && !this.authUser.trim())}
        >
          {app.translator.trans('ernestdefoe-millwright.admin.auth_config.submit_button')}
        </Button>
      </div>
    );
  }

  revealLabel(): string {
    return this.reveal
      ? app.translator.trans('ernestdefoe-millwright.admin.auth_config.hide', {}, true)
      : app.translator.trans('ernestdefoe-millwright.admin.auth_config.show', {}, true);
  }

  // ── plumbing ────────────────────────────────────────────────────────────
}
