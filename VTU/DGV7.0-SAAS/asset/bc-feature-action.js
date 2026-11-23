/* Include CSRF on legacy programmatic action bodies as well as HTML forms. */
function bcFeatureActionBody(){
 var data=new FormData();data.set('_csrf_token',document.querySelector('meta[name="feature-action-csrf"]').content);return data;
}
function bcFeatureActionParams(){
 var data=new URLSearchParams();data.set('_csrf_token',document.querySelector('meta[name="feature-action-csrf"]').content);return data;
}
