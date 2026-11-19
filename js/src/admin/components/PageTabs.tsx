import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';

export interface PageTab {
  id: string;
  label: Mithril.Children;
  /** a count shown beside the label, hidden at 0 */
  badge: number;
}

export interface PageTabsAttrs extends ComponentAttrs {
  tabs: PageTab[];
  active: string;
  choose: (id: string) => void;
}

/** The row of tabs across the top of the Millwright page. */
export default class PageTabs extends Component<PageTabsAttrs> {
  view() {
    return (
      <div className="Millwright-tabs" role="tablist">
        {this.attrs.tabs.map((tab) => (
          <button
            key={tab.id}
            role="tab"
            aria-selected={this.attrs.active === tab.id}
            className={'Millwright-tab' + (this.attrs.active === tab.id ? ' is-active' : '')}
            onclick={() => this.attrs.choose(tab.id)}
          >
            {tab.label}
            {tab.badge > 0 ? <span className="Millwright-count">{tab.badge}</span> : null}
          </button>
        ))}
      </div>
    );
  }
}
