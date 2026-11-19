import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import apiUrl from '../apiUrl';
import { alertError, serverMessage } from '../errors';
import type { ConfigPayload, Repository as Repo } from '../types';
import CredentialsCard, { type ChangeMethod } from './CredentialsCard';

/**
 * Where Composer looks, what it will accept, and how it authenticates.
 *
 * These three settings are what a forum needs before Composer can install
 * anything beyond public Packagist: where a private repository lives, that
 * release candidates are acceptable, and how to authenticate to a paid one — a
 * forum whose own extensions live in private repositories cannot resolve a
 * single one without them.
 *
 * A stored credential is never sent to the browser. This screen shows which
 * hosts have one; entering a value replaces it. That is a deliberate limit, not
 * an oversight — a settings page that displays your token back to you turns
 * every screenshot into a leak.
 *
 * The screen reads a source the way a person names it: "ernestdefoe/bespoke on
 * GitHub", not a URL in a monospace column. Every value it shows is still the
 * exact one Composer gets.
 */

/** What a repository URL points at, for the icon and the readable name. */
function describe(url: string, type: string): { icon: string; name: string; where: string; tone: string } {
  if (type === 'path') {
    return { icon: 'fas fa-folder-open', name: url.replace(/\/+$/, '').split('/').pop() || url, where: url, tone: 'path' };
  }

  const match = /^https?:\/\/([^/]+)\/(.+?)(?:\.git)?\/?$/i.exec(url.trim());
  if (!match) return { icon: 'fas fa-box', name: url, where: '', tone: 'other' };

  const host = match[1].toLowerCase();
  const name = type === 'composer' ? host : match[2];

  if (host.endsWith('github.com')) return { icon: 'fab fa-github', name, where: host, tone: 'github' };
  if (host.includes('gitlab')) return { icon: 'fab fa-gitlab', name, where: host, tone: 'gitlab' };
  if (host.includes('bitbucket')) return { icon: 'fab fa-bitbucket', name, where: host, tone: 'bitbucket' };

  return { icon: type === 'composer' ? 'fas fa-cubes' : 'fas fa-code-branch', name, where: host, tone: 'other' };
}

const TYPES: Record<string, { icon: string }> = {
  vcs: { icon: 'fas fa-code-branch' },
  composer: { icon: 'fas fa-cubes' },
  path: { icon: 'fas fa-folder-open' },
};

const LADDER = ['stable', 'RC', 'beta', 'alpha', 'dev'];

export default class SourcesTab extends Component {
  private loading = true;
  private saving = false;
  private data: ConfigPayload | null = null;
  /** Why the settings could not be read at all, when they could not. */
  private loadError: string | null = null;

  // repositories
  private repoType = 'vcs';
  private repoUrl = '';
  private filter = '';
  private confirmRepo: string | null = null;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  view() {
    if (this.loading) return <LoadingIndicator />;

    const data = this.data;

    if (!data) {
      return (
        <Alert type="error" dismissible={false} className="Millwright-alert">
          {this.loadError || app.translator.trans('ernestdefoe-millwright.admin.composer.config_failed')}
        </Alert>
      );
    }

    return (
      <div className="Millwright-sources Millwright-src">
        {this.overview(data)}
        {this.repositories(data)}
        {this.stability(data)}
        <CredentialsCard data={data} saving={this.saving} send={(method: ChangeMethod, path: string, body: Record<string, unknown>, after?: () => void) => this.send(method, path, body, after)} />
        <p className="Millwright-src-note">
          <i className="fas fa-circle-info" aria-hidden="true" /> {data.note}
        </p>
      </div>
    );
  }

  // ── at a glance ─────────────────────────────────────────────────────────

  /**
   * Three answers before any detail: where Composer looks, how finished a
   * release must be, and how many hosts it can sign in to. Each tile jumps to
   * the card that changes it.
   */
  overview(data: ConfigPayload) {
    const repos = data.repositories || [];
    const stored = data.auth?.stored || [];
    const level = data.stability?.minimumStability || 'stable';

    const tile = (icon: string, value: Mithril.Children, label: Mithril.Children, target: string) => (
      <a
        className="Millwright-src-stat"
        href={'#' + target}
        onclick={(e: Event) => {
          e.preventDefault();
          document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }}
      >
        <span className="Millwright-src-statIcon">
          <i className={icon} aria-hidden="true" />
        </span>
        <span className="Millwright-src-statText">
          <strong>{value}</strong>
          <span>{label}</span>
        </span>
      </a>
    );

    return (
      <div className="Millwright-src-overview">
        {tile(
          'fas fa-code-branch',
          repos.length,
          app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.stat', { count: repos.length }),
          'mw-src-repos'
        )}
        {tile(
          'fas fa-flask',
          app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.options.' + level.toLowerCase()),
          app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.stat'),
          'mw-src-stability'
        )}
        {tile(
          'fas fa-key',
          stored.length,
          app.translator.trans('ernestdefoe-millwright.admin.auth_config.stat', { count: stored.length }),
          'mw-src-auth'
        )}
      </div>
    );
  }

  // ── repositories ────────────────────────────────────────────────────────

  repositories(data: ConfigPayload) {
    const repos = data.repositories || [];
    const q = this.filter.trim().toLowerCase();
    const shown = q ? repos.filter((r) => r.url.toLowerCase().includes(q)) : repos;

    return (
      <section className="Millwright-src-card" id="mw-src-repos">
        <header className="Millwright-src-head">
          <div className="Millwright-src-headIcon">
            <i className="fas fa-code-branch" aria-hidden="true" />
          </div>
          <div className="Millwright-src-headText">
            <h3>
              {app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.label')}{' '}
              <span className="Millwright-src-count">{repos.length}</span>
            </h3>
            <p>{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.help')}</p>
          </div>
          {repos.length > 6 ? (
            <label className="Millwright-src-filter">
              <i className="fas fa-magnifying-glass" aria-hidden="true" />
              <input
                type="search"
                placeholder={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.filter', {}, true)}
                aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.filter', {}, true)}
                value={this.filter}
                oninput={(e: InputEvent) => (this.filter = (e.target as HTMLInputElement).value)}
              />
            </label>
          ) : null}
        </header>

        {repos.length === 0 ? (
          <div className="Millwright-src-empty">
            <i className="fas fa-box-open" aria-hidden="true" />
            <span>{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.none')}</span>
          </div>
        ) : shown.length === 0 ? (
          <div className="Millwright-src-empty">
            <span>{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.no_match', { query: this.filter })}</span>
          </div>
        ) : (
          this.groups(shown).map((g) => (
            <div className="Millwright-src-group" key={g.key}>
              <div className="Millwright-src-groupHead">
                <span className={'Millwright-src-logo Millwright-src-logo--sm Millwright-src-logo--' + g.tone}>
                  <i className={g.icon} aria-hidden="true" />
                </span>
                <strong>{g.owner}</strong>
                {g.where ? <span>{g.where}</span> : null}
                <span className="Millwright-src-count">{g.repos.length}</span>
              </div>
              <ul className="Millwright-src-tiles">{g.repos.map((r) => this.repoTile(r, g.owner))}</ul>
            </div>
          ))
        )}

        {this.addRepo(data, repos)}
      </section>
    );
  }

  /**
   * Grouped by owner and host, so "ernestdefoe · github.com" is said once
   * instead of twenty-two times. Most forums keep their private extensions
   * under one account, and the repeated half of every row was the clutter.
   */
  groups(repos: Repo[]) {
    const out: { key: string; owner: string; where: string; icon: string; tone: string; repos: Repo[] }[] = [];

    for (const r of repos) {
      const d = describe(r.url, r.type);
      const slash = r.type === 'vcs' ? d.name.indexOf('/') : -1;
      const owner = slash > 0 ? d.name.slice(0, slash) : this.typeLabel(r.type);
      const key = owner + '@' + (slash > 0 ? d.where : r.type);
      let g = out.find((x) => x.key === key);

      if (!g) {
        g = {
          key,
          owner,
          where: slash > 0 ? d.where : '',
          icon: slash > 0 ? d.icon : (TYPES[r.type] || TYPES.vcs).icon,
          tone: slash > 0 ? d.tone : 'other',
          repos: [],
        };
        out.push(g);
      }

      g.repos.push(r);
    }

    return out;
  }

  repoTile(r: Repo, owner: string) {
    const d = describe(r.url, r.type);
    const confirming = this.confirmRepo === r.url;
    const linkable = /^https?:\/\//i.test(r.url);
    const short = d.name.startsWith(owner + '/') ? d.name.slice(owner.length + 1) : d.name;

    return (
      <li className={'Millwright-src-tile' + (confirming ? ' is-confirming' : '')} key={r.url} title={r.url}>
        {confirming ? (
          <div className="Millwright-src-tileConfirm">
            <span>{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.remove_named', { name: short })}</span>
            <span>
              <Button className="Button Button--sm" onclick={() => (this.confirmRepo = null)}>
                {app.translator.trans('ernestdefoe-millwright.admin.actions.cancel')}
              </Button>
              <Button
                className="Button Button--sm Button--danger"
                disabled={this.saving}
                onclick={() => this.send('DELETE', '/repositories', { url: r.url }, () => (this.confirmRepo = null))}
              >
                {app.translator.trans('ernestdefoe-millwright.admin.actions.remove')}
              </Button>
            </span>
          </div>
        ) : (
          [
            <span className="Millwright-src-tileName">{short}</span>,
            r.type !== 'vcs' ? <span className="Millwright-src-badge">{this.typeLabel(r.type)}</span> : null,
            <span className="Millwright-src-tileActions">
              {linkable ? (
                <a
                  className="Millwright-src-icon Millwright-src-icon--sm"
                  href={r.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.open', {}, true)}
                  aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.open', {}, true)}
                >
                  <i className="fas fa-arrow-up-right-from-square" aria-hidden="true" />
                </a>
              ) : null}
              <button
                className="Millwright-src-icon Millwright-src-icon--sm Millwright-src-icon--danger"
                disabled={this.saving}
                title={app.translator.trans('ernestdefoe-millwright.admin.actions.remove', {}, true)}
                aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.delete_repository_label', { name: d.name }, true)}
                onclick={() => (this.confirmRepo = r.url)}
              >
                <i className="fas fa-trash-can" aria-hidden="true" />
              </button>
            </span>,
          ]
        )}
      </li>
    );
  }

  /** A repository type's name: "Git repository", "Folder on this server"… */
  typeLabel(type: string): string {
    return app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.types.' + type, {}, true);
  }

  addRepo(data: ConfigPayload, repos: Repo[]) {
    const types = data.types || ['vcs', 'composer', 'path'];
    const url = this.repoUrl.trim();
    const d = url ? describe(url, this.repoType) : null;
    const duplicate = url !== '' && repos.some((r) => r.url.replace(/\/+$/, '') === url.replace(/\/+$/, ''));

    return (
      <form
        className="Millwright-src-add"
        onsubmit={(e: Event) => {
          e.preventDefault();
          if (!url || duplicate) return;
          this.send('POST', '/repositories', { type: this.repoType, url }, () => (this.repoUrl = ''));
        }}
      >
        <div className="Millwright-src-addTitle">{app.translator.trans('ernestdefoe-millwright.admin.composer.add_repository_label')}</div>

        <div
          className="Millwright-src-choices"
          role="radiogroup"
          aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.add_repository_label', {}, true)}
        >
          {types.map((ty) => (
            <button
              type="button"
              role="radio"
              aria-checked={this.repoType === ty ? 'true' : 'false'}
              className={'Millwright-src-choice' + (this.repoType === ty ? ' is-on' : '')}
              onclick={() => (this.repoType = ty)}
            >
              <i className={(TYPES[ty] || TYPES.vcs).icon} aria-hidden="true" />
              <span>
                <strong>{this.typeLabel(ty)}</strong>
                <small>{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.type_help.' + ty)}</small>
              </span>
            </button>
          ))}
        </div>

        <div className="Millwright-src-field">
          <span className={'Millwright-src-fieldIcon' + (d ? ' Millwright-src-logo--' + d.tone : '')}>
            <i className={d ? d.icon : (TYPES[this.repoType] || TYPES.vcs).icon} aria-hidden="true" />
          </span>
          <input
            placeholder={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.placeholders.' + this.repoType, {}, true)}
            aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.placeholders.' + this.repoType, {}, true)}
            value={this.repoUrl}
            oninput={(e: InputEvent) => (this.repoUrl = (e.target as HTMLInputElement).value)}
          />
          <Button className="Button Button--primary" type="submit" icon="fas fa-plus" disabled={this.saving || !url || duplicate}>
            {app.translator.trans('ernestdefoe-millwright.admin.actions.add')}
          </Button>
        </div>

        {/*
         * Says what will be added before it is, in the same words the list
         * uses — or why it won't be.
         */}
        {duplicate ? (
          <div className="Millwright-src-hint is-warn">{app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.duplicate')}</div>
        ) : d && d.where ? (
          <div className="Millwright-src-hint">
            {app.translator.trans('ernestdefoe-millwright.admin.composer.repositories.will_add', { name: d.name, where: d.where })}
          </div>
        ) : null}
      </form>
    );
  }

  // ── stability ───────────────────────────────────────────────────────────

  stability(data: ConfigPayload) {
    const st = data.stability || { minimumStability: 'stable', preferStable: false };
    const current = st.minimumStability || 'stable';
    const levels = st.levels || LADDER;
    const means = (l: string) => app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.means.' + l.toLowerCase(), {}, true);

    return (
      <section className="Millwright-src-card" id="mw-src-stability">
        <header className="Millwright-src-head">
          <div className="Millwright-src-headIcon">
            <i className="fas fa-flask" aria-hidden="true" />
          </div>
          <div className="Millwright-src-headText">
            <h3>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.label')}</h3>
            <p>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.help')}</p>
          </div>
        </header>

        <div className="Millwright-src-body">
          {/*
           * A ladder, most finished first, because that is what the setting
           * is: everything at and above the chosen step is accepted. A select
           * hid the other four choices and the order between them.
           */}
          <div
            className="Millwright-src-ladder"
            role="radiogroup"
            aria-label={app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.label', {}, true)}
          >
            {levels.map((l, i) => {
              const on = l === current;
              const accepted = i <= levels.indexOf(current);

              return (
                <button
                  type="button"
                  role="radio"
                  aria-checked={on ? 'true' : 'false'}
                  title={means(l)}
                  disabled={this.saving}
                  className={'Millwright-src-step' + (on ? ' is-on' : accepted ? ' is-accepted' : '')}
                  onclick={() => !on && this.send('PATCH', '/stability', { minimumStability: l, preferStable: st.preferStable })}
                >
                  <strong>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.options.' + l.toLowerCase())}</strong>
                  {l === 'beta' ? (
                    <small className="Millwright-src-reco">
                      {app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.recommended')}
                    </small>
                  ) : (
                    <small>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.short.' + l.toLowerCase())}</small>
                  )}
                </button>
              );
            })}
          </div>

          <div className="Millwright-src-callout">
            <i className="fas fa-lightbulb" aria-hidden="true" />
            <span>{means(current)}</span>
          </div>

          <label className="Millwright-src-switch">
            <input
              type="checkbox"
              role="switch"
              checked={!!st.preferStable}
              disabled={this.saving}
              onchange={(e: Event) =>
                this.send('PATCH', '/stability', { minimumStability: current, preferStable: (e.target as HTMLInputElement).checked })
              }
            />
            <span className="Millwright-src-track" aria-hidden="true">
              <span />
            </span>
            <span className="Millwright-src-switchText">
              <strong>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.prefer_stable')}</strong>
              <small>{app.translator.trans('ernestdefoe-millwright.admin.composer.minimum_stability.prefer_stable_help')}</small>
            </span>
          </label>
        </div>
      </section>
    );
  }

  load() {
    app
      .request<ConfigPayload>({ method: 'GET', url: apiUrl() + '/millwright/config', errorHandler: () => {} })
      .then((data) => {
        this.data = data;
        this.loading = false;
        m.redraw();
      })
      .catch((e) => {
        this.loading = false;
        this.loadError = serverMessage(e);
        m.redraw();
      });
  }

  send(method: ChangeMethod, path: string, body: Record<string, unknown>, after?: () => void) {
    this.saving = true;
    m.redraw();

    app
      .request<ConfigPayload>({
        method,
        url: apiUrl() + '/millwright/config' + path,
        body,
        /*
         * The server's own words. Every refusal from the Config classes is
         * written for the person who typed the value — "that is not a hostname"
         * tells them what to change; "could not save" does not.
         */
        errorHandler: alertError('ernestdefoe-millwright.admin.composer.config_failed'),
      })
      .then((data) => {
        this.data = data;
        this.saving = false;
        if (after) after();
        m.redraw();
      })
      .catch(() => {
        this.saving = false;
        m.redraw();
      });
  }
}
