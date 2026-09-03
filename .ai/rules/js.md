---
paths:
  - 'resources/js/**'
---

# Js

## Reference routes through Wayfinder controller objects, not named-route imports
In Vue files, import the Wayfinder controller object and call methods on it:

    import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
    // PlaylistController.index()   PlaylistController.show(id)

Do NOT import named-route helpers from `@/routes/*`, and never alias them
(`import { index as playlistIndex }`). Bare `index`/`show`/`create` collide
across resources, which forces aliases and hides the target.

Why: the qualified `Controller.method` form makes renames mechanical and
"find usages" reliable in the IDE and in grep — the controller name is right
there at the call site. It is also the form the starter kit already uses
(see `pages/user-profile/Edit.vue` with `ProfileController.update.form()`).

Wayfinder regenerates `resources/js/actions/**` on build; never hand-edit it.
