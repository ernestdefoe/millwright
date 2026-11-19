import app from 'flarum/admin/app';

/** "5 minutes ago", "3 hours ago", "4 days ago": how long since a unix time. */
export default function ago(unix: number): string {
  const mins = Math.max(1, Math.round((Date.now() / 1000 - unix) / 60));

  if (mins < 60) return app.translator.trans('ernestdefoe-millwright.admin.updater.ago.minutes', { count: mins }, true) as string;

  const hours = Math.round(mins / 60);

  return (
    hours < 48
      ? app.translator.trans('ernestdefoe-millwright.admin.updater.ago.hours', { count: hours }, true)
      : app.translator.trans('ernestdefoe-millwright.admin.updater.ago.days', { count: Math.round(hours / 24) }, true)
  ) as string;
}
