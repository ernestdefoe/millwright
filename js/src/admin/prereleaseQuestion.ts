import app from 'flarum/admin/app';
import type RequestError from 'flarum/common/utils/RequestError';
import { confirmAction } from './components/ConfirmModal';
import { alertError, errorPayload, serverMessage } from './errors';

/**
 * The start's `errorHandler`. A refusal over installed betas the forum's
 * stability no longer allows is a question, not an error: the server names
 * them and explains, and only the admin's yes (`retry`) adds the flags (see
 * Plan\LockedPrereleases). Every other refusal is shown as before.
 */
export function startRefused(retry: () => void) {
  return (e: RequestError): void => {
    if (errorPayload(e).errors?.[0]?.code !== 'locked_prereleases') {
      alertError('ernestdefoe-millwright.admin.run.start_failed')(e);
      return;
    }

    confirmAction({
      title: app.translator.trans('ernestdefoe-millwright.admin.extensions.update'),
      message: serverMessage(e) || '',
      confirmText: app.translator.trans('ernestdefoe-millwright.admin.extensions.prereleases_confirm'),
      onconfirm: retry,
    });
  };
}
