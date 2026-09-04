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
  update: string | null;
}

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

export default class MillwrightPage extends ExtensionPage {
  loading = true;
  host: any = null;
  installed: Installed[] = [];
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
            </button>
            <button
              className={'Button' + (this.tab === 'host' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'host')}
            >
              {t('tab_host')}
            </button>
          </div>

          {this.tab === 'host' ? <HostPanel host={this.host} /> : this.grid()}
        </div>
      </div>
    );
  }

  grid() {
    return (
      <div className="Millwright-grid">
        {this.installed.map((e) => (
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
              <span className={'Millwright-tag' + (e.enabled ? ' Millwright-tag--ok' : '')}>
                {e.enabled ? t('enabled') : t('disabled')}
              </span>
            </div>
          </div>
        ))}
      </div>
    );
  }
}
