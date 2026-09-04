import Extend from 'flarum/common/extenders';
import MillwrightPage from './components/MillwrightPage';

/*
 * 🚨 Declarative, not `app.extensionData` at initializer time.
 *
 * Both work at runtime, but the extender is the typed path — reaching for
 * app.extensionData means casting through `any` to get past a gap in Flarum 2's
 * dist typings, and a cast is exactly where a rename upstream stops being a
 * compile error and starts being a page that silently never renders.
 */
export default [new Extend.Admin().page(MillwrightPage)];
