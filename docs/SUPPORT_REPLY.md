Thank you for explaining your workflow and the problems you encountered.

You are correct that Settings already includes **Generated CPT Slug Base**. That setting changes the prefix used by PageForge custom content, for example `/pageforge/the-magnolias/` to `/locations/the-magnolias/`. It does not change the output post type or remove the CPT prefix entirely. Saving a different valid base refreshes the permalink rules. If changing that setting did not update your custom-content links, that needs investigation rather than being dismissed as a missing feature.

For `/the-magnolias/`, edit the generation template and select **Target Post Type → Page**, with **Parent Page → None**. Native Page and Post generation is included in Free. A template's saved target overrides the global default, which can explain why changing only the global setting still produced PageForge custom content. The update adds **Use global default** and shows the effective output destination before generation.

We have also added one existing **Parent Page** per template in Free and Premium. Select the `maths-tuition-gurgaon` Page as parent to generate children such as `/maths-tuition-gurgaon/dlf-phase-iv/`. **Base Layout to Clone** is separate from the parent setting. Automatic parent mapping from each CSV row by Parent ID or Parent Path requires licensed Premium; a static parent does not.

These settings apply to newly generated content. Existing items are not automatically converted or moved. Changing an established CPT base affects existing links, so redirects must be arranged separately.

Please test the supplied update on a backup or staging copy first. If the slug-base setting still fails, please share the plugin version, the selected template target, and the old and new base values so we can investigate the exact behavior.
