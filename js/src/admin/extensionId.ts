/** Flarum's id for an extension package, as core derives it (Extension::nameToId). */
export default function extensionId(name: string): string {
  const at = name.indexOf('/');
  const pkg = at < 0 ? name : name.slice(at + 1);
  return (at < 0 ? name : name.slice(0, at)) + '-' + pkg.replace(/flarum-ext-|flarum-/g, '');
}
