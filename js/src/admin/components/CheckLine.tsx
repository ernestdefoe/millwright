import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type { Updates } from '../types';
import ago from '../ago';

type CheckAge = 'fresh' | 'recent' | 'overdue';

export interface CheckLineAttrs extends ComponentAttrs {
  updates: Updates;
  /** extensions with a newer version */
  count: number;
  coreNewer: boolean;
  checking: boolean;
  check: () => void;
}

/** The Installed tab's line about the last update check, and the button that runs one. */
export default class CheckLine extends Component<CheckLineAttrs> {
  /**
   * The count is always shown WITH its age and its blind spots. "1 newer
   * version" is a claim; "1 newer version, checked 2 hours ago, 9 packages could
   * not be checked" is the truth, and only the second lets somebody decide
   * whether to believe it.
   */
  view() {
    const n = this.attrs.count;
    const uncheckable = (this.attrs.updates.uncheckable || []).length;
    const tracking = (this.attrs.updates.tracking || []).length;
    const age = this.checkAge();

    // Never checked: there is no result to state, only the prompt (ClaudiusH, 2026-10-09:
    // "up to date" beside "check for updates" contradicted itself).
    const checkedAt = this.attrs.updates.checkedAt;

    if (!checkedAt) {
      return (
        <div className="Millwright-checkline Millwright-checkline--overdue">
          <p className="Millwright-checkPrompt">{app.translator.trans('ernestdefoe-millwright.admin.updater.check_overdue')}</p>
          {this.checkButton(age)}
        </div>
      );
    }

    return (
      <div className={'Millwright-checkline Millwright-checkline--' + age}>
        {age === 'overdue' ? (
          <p className="Millwright-checkPrompt">{app.translator.trans('ernestdefoe-millwright.admin.updater.check_overdue')}</p>
        ) : null}
        <span>
          <b>
            {n === 0 && !this.attrs.coreNewer
              ? app.translator.trans('ernestdefoe-millwright.admin.updater.up_to_date')
              : n === 0
              ? app.translator.trans('ernestdefoe-millwright.admin.updater.core_newer')
              : [
                  app.translator.trans('ernestdefoe-millwright.admin.updater.some_newer', { count: n }),
                  this.attrs.coreNewer ? ' ' + app.translator.trans('ernestdefoe-millwright.admin.updater.core_newer_too', {}, true) : '',
                ]}
          </b>{' '}
          {app.translator.trans('ernestdefoe-millwright.admin.updater.last_update_checked_at', { when: ago(checkedAt) })}
          {uncheckable > 0 ? ' ' + app.translator.trans('ernestdefoe-millwright.admin.updater.uncheckable', { count: uncheckable }, true) : ''}
          {tracking > 0 ? ' ' + app.translator.trans('ernestdefoe-millwright.admin.updater.tracking', { count: tracking }, true) : ''}
        </span>
        {this.checkButton(age)}
      </div>
    );
  }

  /**
   * How hard to ask, by how old the last check is (ClaudiusH, 2026-10-09):
   * within the week, an ordinary button; older than that, or never, a primary
   * button and a prompt that says why it matters. Always a button, never a
   * link: it is the main action on the Installed tab (ClaudiusH, 2026-10-27).
   */
  checkAge(): CheckAge {
    const at = this.attrs.updates.checkedAt;
    if (!at) return 'overdue';

    const days = (Date.now() / 1000 - at) / 86400;

    return days < 1 ? 'fresh' : days <= 7 ? 'recent' : 'overdue';
  }

  checkButton(age: CheckAge) {
    const className = age === 'overdue' ? 'Button Button--primary' : 'Button';

    return (
      <Button
        className={className + ' Millwright-checkNow'}
        disabled={this.attrs.checking}
        // A spinner as well as the words: "Checking…" alone reads as a label.
        icon={this.attrs.checking ? 'fas fa-circle-notch fa-spin' : undefined}
        onclick={() => this.attrs.check()}
      >
        {this.attrs.checking
          ? app.translator.trans('ernestdefoe-millwright.admin.updater.checking')
          : age === 'fresh'
          ? app.translator.trans('ernestdefoe-millwright.admin.updater.check_now')
          : app.translator.trans('ernestdefoe-millwright.admin.updater.check_for_updates')}
      </Button>
    );
  }
}
