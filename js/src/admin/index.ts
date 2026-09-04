import app from 'flarum/admin/app';

app.initializers.add('ernestdefoe/millwright', () => {
  // The settings page is registered declaratively in ./extend.
});

export { default as extend } from './extend';
