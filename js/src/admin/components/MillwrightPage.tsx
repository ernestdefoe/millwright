import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import HostPanel from './HostPanel';

declare const m: any;

interface Installed {
  id: string;
  name: string;
  package: string;
  version: string;
  icon: { backgroundColor?: string; color?: string; name?: string } | null;
  enabled: boolean;
  /** A hint from the cheap check: a newer version exists. Not a promise. */
  update: { from: string; to: string } | null;
}

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

export default class MillwrightPage extends ExtensionPage {
  loading = true;
  host: any = null;
  installed: Installed[] = [];
  updates: any = { available: {}, checkedAt: null, stale: true, uncheckable: [] };
  checking = false;
  tab: 'installed' | 'host' = 'installed';

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    app
      .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/millwright/state' })
      .then((data: any) => {
        this.host = data.host;
        this.installed = data.installed || [];
        this.updates = data.updates || this.updates;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  content() {
    if (this.loading) {
      return (
        <div className="ExtensionPage-settings">
          <div className="container">
            <LoadingIndicator />
          </div>
        </div>
      );
    }

    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          {/*
            * 🚨 Said plainly, at the top, rather than by disabling a button and
            * leaving people to work it out. Millwright can inspect a host today;
            * it cannot run updates until the Composer work lands. A tool whose
            * whole pitch is that it tells you the truth has to start by telling
            * the truth about itself.
            */}
          <div className="Millwright-notice">{t('not_yet_updating')}</div>

          <div className="ButtonGroup" style={{ marginBottom: '20px' }}>
            <button
              className={'Button' + (this.tab === 'installed' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'installed')}
            >
              {t('tab_installed', { count: this.installed.length })}
              {this.updateCount() > 0 ? (
                <span className="Millwright-count">{this.updateCount()}</span>
              ) : null}
            </button>
            <button
              className={'Button' + (this.tab === 'host' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'host')}
            >
              {t('tab_host')}
            </button>
          </div>

          {this.tab === 'host' ? <HostPanel host={this.host} /> : [this.checkLine(), this.grid()]}
        </div>
      </div>
    );
  }

  updateCount(): number {
    return Object.keys(this.updates?.available || {}).length;
  }

  /**
   * 🚨 The count is always shown WITH its age and its blind spots. "1 newer
   * version" is a claim; "1 newer version, checked 2 hours ago, 9 packages could
   * not be checked" is the truth, and only the second lets somebody decide
   * whether to believe it.
   */
  checkLine() {
    const n = this.updateCount();
    const uncheckable = (this.updates?.uncheckable || []).length;

    return (
      <div className="Millwright-checkline" key="checkline">
        <span>
          <b>{n === 0 ? t('none_newer') : t('some_newer', { count: n })}</b>{' '}
          {this.updates?.checkedAt ? t('checked_ago', { when: this.ago(this.updates.checkedAt) }) : t('never_checked')}
          {uncheckable > 0 ? ' ' + t('uncheckable', { count: uncheckable }) : ''}
        </span>
        <button className="Button Button--link" disabled={this.checking} onclick={() => this.checkNow()}>
          {this.checking ? t('checking') : t('check_now')}
        </button>
      </div>
    );
  }

  checkNow() {
    this.checking = true;
    m.redraw();

    app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/millwright/check' })
      .then((data: any) => {
        this.updates = data.updates || this.updates;
        this.installed = data.installed || this.installed;
        this.checking = false;
        m.redraw();
      })
      .catch(() => {
        this.checking = false;
        m.redraw();
      });
  }

  ago(unix: number): string {
    const mins = Math.max(1, Math.round(Date.now() / 1000 - unix) / 60);
    if (mins < 60) return t('ago_minutes', { count: Math.round(mins) }) as unknown as string;
    const hours = Math.round(mins / 60);
    return hours < 48
      ? (t('ago_hours', { count: hours }) as unknown as string)
      : (t('ago_days', { count: Math.round(hours / 24) }) as unknown as string);
  }

  /** Anything with a newer version first — that is what somebody came to see. */
  sorted(): Installed[] {
    return [...this.installed].sort((a, b) => Number(!!b.update) - Number(!!a.update));
  }

  grid() {
    return (
      <div className="Millwright-grid" key="grid">
        {this.sorted().map((e) => (
          <div className="Millwright-card" key={e.id}>
            <div className="Millwright-cardTop">
              <div
                className="Millwright-icon"
                style={{ background: e.icon?.backgroundColor || 'var(--primary-color)' }}
              >
                {e.icon?.name ? <i className={e.icon.name} /> : e.name.charAt(0)}
              </div>
              <div>
                <div className="Millwright-name">{e.name}</div>
                <div className="Millwright-pkg">{e.package}</div>
              </div>
            </div>

            <div className="Millwright-meta">
              <span>{e.version || t('version_unknown')}</span>
            </div>

            <div className="Millwright-foot">
              {e.update ? (
                <span className="Millwright-tag Millwright-tag--warn">
                  {e.update.from} → {e.update.to}
                </span>
              ) : (
                <span className={'Millwright-tag' + (e.enabled ? ' Millwright-tag--ok' : '')}>
                  {e.enabled ? t('enabled') : t('disabled')}
                </span>
              )}
            </div>
          </div>
        ))}
      </div>
    );
  }
}
