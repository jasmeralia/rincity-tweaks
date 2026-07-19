/* global rincwcImportCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcImportCfg.nonce ) );

    const base = rincwcImportCfg.restBase;
    let payload = null;
    let diff = null;

    function api( path, method, data ) {
        const options = { url: base + path, method: method || 'GET' };
        if ( data ) options.data = data;
        return wp.apiFetch( options );
    }

    function errorMessage( error ) {
        return error && ( error.message || error.error ) ? ( error.message || error.error ) : 'Unknown error';
    }

    function setStatus( message, isError ) {
        const status = document.getElementById( 'rincwc-import-status' );
        status.textContent = message;
        status.classList.toggle( 'rincwc-import-errors', !! isError );
    }

    function element( tag, text, className ) {
        const node = document.createElement( tag );
        if ( text !== undefined && text !== null ) node.textContent = String( text );
        if ( className ) node.className = className;
        return node;
    }

    function displayValue( value ) {
        if ( value === null || value === undefined || value === '' ) return '—';
        if ( typeof value === 'object' ) return JSON.stringify( value );
        return String( value );
    }

    function displayLogin( login ) {
        const value = login || '';
        return value.endsWith( 'MK1776' ) ? 'MK1776' : value;
    }

    function table( headers ) {
        const node = element( 'table', null, 'widefat striped' );
        const thead = element( 'thead' );
        const row = element( 'tr' );
        headers.forEach( header => row.appendChild( element( 'th', header ) ) );
        thead.appendChild( row );
        node.appendChild( thead );
        node.appendChild( element( 'tbody' ) );
        return node;
    }

    function appendCell( row, content, className ) {
        const cell = element( 'td', null, className );
        if ( content instanceof Node ) cell.appendChild( content );
        else cell.textContent = content === undefined || content === null ? '' : String( content );
        row.appendChild( cell );
        return cell;
    }

    function renderImageDiff( report ) {
        const heading = element( 'h3', 'Images' );
        report.appendChild( heading );
        const imageTable = table( [ 'Use import', 'Image', 'Result', 'Differences' ] );
        const body = imageTable.tBodies[ 0 ];

        diff.images.forEach( image => {
            const row = element( 'tr' );
            const choice = element( 'td' );
            if ( image.result === 'would_overwrite' ) {
                const input = element( 'input' );
                input.type = 'checkbox';
                input.className = 'rincwc-import-image-choice';
                input.value = image.image_key;
                input.setAttribute( 'aria-label', 'Use imported values for ' + image.image_key );
                choice.appendChild( input );
            } else if ( image.result === 'would_add' ) {
                choice.textContent = 'automatic';
            } else {
                choice.textContent = '—';
            }
            row.appendChild( choice );
            appendCell( row, `${image.gallery || 'Gallery'} / ${image.filename || image.image_key} (${image.image_key})` );
            appendCell( row, image.result.replaceAll( '_', ' ' ) );
            const changes = element( 'div', null, 'rincwc-import-value' );
            if ( image.changes && image.changes.length ) {
                image.changes.forEach( change => {
                    changes.appendChild( element( 'div', `${change.field}: local ${displayValue( change.local )} → import ${displayValue( change.import )}` ) );
                } );
            } else if ( image.result === 'id_mismatch' ) {
                changes.textContent = `Local identity: ${image.local_slug || '—'} / ${image.local_filename || '—'}`;
            } else {
                changes.textContent = '—';
            }
            appendCell( row, changes );
            body.appendChild( row );
        } );
        report.appendChild( imageTable );
    }

    function renderCommentDiff( report ) {
        report.appendChild( element( 'h3', 'Comments' ) );
        const comments = diff.comments;
        const summary = element(
            'p',
            `${comments.comment_add.length} add, ${comments.comment_unchanged.length} unchanged, ${comments.comment_conflict.length} conflict, ${comments.comment_skipped.length} skipped.`
        );
        report.appendChild( summary );
        const total = comments.comment_add.length + comments.comment_unchanged.length + comments.comment_conflict.length + comments.comment_skipped.length;
        if ( ! total ) return;

        const commentTable = table( [ 'Resolution', 'Comment identity', 'Result / versions and provenance' ] );
        [
            [ 'comment_add', 'automatic' ],
            [ 'comment_unchanged', 'no change' ],
            [ 'comment_skipped', 'skipped' ],
        ].forEach( ( [ result, resolution ] ) => {
            comments[ result ].forEach( comment => {
                const row = element( 'tr' );
                appendCell( row, resolution );
                appendCell( row, `${comment.gallery || 'Gallery'} / ${comment.filename || comment.image_key}\n${displayLogin( comment.user_login ) || '(missing user)'} at ${comment.created_at}`, 'rincwc-import-value' );
                const detail = result === 'comment_skipped'
                    ? comment.reason
                    : `${result.replaceAll( '_', ' ' )}: from ${comment.import_hostname || diff.source.hostname || '(unknown host)'}, edited ${comment.import_updated_at || '—'}\n${comment.import_body || ''}`;
                appendCell( row, detail, 'rincwc-import-value' );
                commentTable.tBodies[ 0 ].appendChild( row );
            } );
        } );
        comments.comment_conflict.forEach( comment => {
            const row = element( 'tr' );
            const select = element( 'select' );
            select.className = 'rincwc-import-comment-choice';
            select.dataset.conflictId = comment.conflict_id;
            const local = element( 'option', 'Keep local' );
            local.value = 'local';
            const imported = element( 'option', 'Use imported' );
            imported.value = 'import';
            select.append( local, imported );
            appendCell( row, select );
            appendCell( row, `${comment.gallery || 'Gallery'} / ${comment.filename || comment.image_key}\n${displayLogin( comment.user_login ) || '(missing user)'} at ${comment.created_at}`, 'rincwc-import-value' );

            const versions = element( 'div', null, 'rincwc-import-conflict' );
            const importVersion = element( 'div' );
            importVersion.appendChild( element( 'strong', `Import: from ${comment.import_hostname || '(unknown host)'}, edited ${comment.import_updated_at}` ) );
            importVersion.appendChild( element( 'p', comment.import_body ) );
            const localVersion = element( 'div' );
            localVersion.appendChild( element( 'strong', `Local: from ${comment.local_hostname || '(unknown host)'}, edited ${comment.local_updated_at}` ) );
            localVersion.appendChild( element( 'p', comment.local_body ) );
            versions.append( importVersion, localVersion );
            appendCell( row, versions );
            commentTable.tBodies[ 0 ].appendChild( row );
        } );
        report.appendChild( commentTable );
    }

    function renderWatermarkDiff( report ) {
        report.appendChild( element( 'h3', 'Watermarks' ) );
        const watermarkTable = table( [ 'Name', 'Default', 'Result', 'Matched by', 'Content' ] );
        diff.watermarks.forEach( watermark => {
            const row = element( 'tr' );
            appendCell( row, watermark.name );
            appendCell( row, watermark.is_default ? 'yes' : 'no' );
            appendCell( row, watermark.result );
            appendCell( row, watermark.matched_by || '—' );
            let content = '—';
            let contentClass = '';
            if ( watermark.content_differs ) {
                content = 'Warning: name matches, but PNG content differs from the import.';
                contentClass = 'rincwc-import-fallbacks';
            } else if ( watermark.matched_by === 'sha256' ) {
                content = 'Same SHA-256';
            } else if ( watermark.matched_by === 'name' ) {
                content = 'Name-only match; local content could not be verified';
                contentClass = 'rincwc-import-fallbacks';
            }
            appendCell( row, content, contentClass );
            watermarkTable.tBodies[ 0 ].appendChild( row );
        } );
        report.appendChild( watermarkTable );
    }

    function renderSettingsDiff( report ) {
        report.appendChild( element( 'h3', 'Gallery Settings' ) );
        const settingsTable = table( [ 'Type', 'Gallery', 'Import value', 'Local value', 'Result' ] );
        diff.cutoffs.forEach( cutoff => {
            const row = element( 'tr' );
            appendCell( row, 'Cutoff' );
            appendCell( row, `${cutoff.gallery_title || cutoff.gallery_slug || 'Gallery'} (#${cutoff.gallery_id})` );
            appendCell( row, cutoff.position );
            appendCell( row, displayValue( cutoff.local_position ) );
            appendCell( row, cutoff.result );
            settingsTable.tBodies[ 0 ].appendChild( row );
        } );
        diff.gallery_watermark_overrides.forEach( override => {
            const row = element( 'tr' );
            appendCell( row, 'Watermark override' );
            appendCell( row, `${override.gallery_slug || 'Gallery'} (#${override.gallery_id})` );
            appendCell( row, override.watermark_sha256 );
            appendCell( row, override.local_watermark_id || 'default' );
            appendCell( row, override.result );
            settingsTable.tBodies[ 0 ].appendChild( row );
        } );
        report.appendChild( settingsTable );
    }

    function renderFallbacks( report ) {
        if ( ! diff.user_fallbacks.length ) return;
        const box = element( 'div', null, 'notice notice-warning inline rincwc-import-fallbacks' );
        box.appendChild( element( 'p', 'Missing local users will be attributed to the importing admin:' ) );
        const list = element( 'ul' );
        diff.user_fallbacks.forEach( fallback => {
            list.appendChild( element( 'li', `${fallback.entity} on ${fallback.image_key}: ${displayLogin( fallback.source_login ) || '(missing login)'} → ${displayLogin( fallback.attributed_to )}` ) );
        } );
        box.appendChild( list );
        report.appendChild( box );
    }

    function renderDiff() {
        const report = document.getElementById( 'rincwc-import-report' );
        report.replaceChildren();
        report.hidden = false;
        report.appendChild( element( 'h3', 'Import source' ) );
        report.appendChild( element( 'p', `${diff.source.site || '(unknown site)'} on ${diff.source.hostname || '(unknown host)'}, exported ${diff.source.exported_at || '(unknown time)'} with plugin ${diff.source.plugin_version || '(unknown)'}.` ) );
        renderFallbacks( report );
        renderImageDiff( report );
        renderCommentDiff( report );
        renderWatermarkDiff( report );
        renderSettingsDiff( report );
        document.getElementById( 'rincwc-import-actions' ).hidden = false;
        document.getElementById( 'rincwc-import-final' ).hidden = true;
    }

    function selectedOptions() {
        const overwriteImageKeys = Array.from( document.querySelectorAll( '.rincwc-import-image-choice:checked' ) ).map( input => input.value );
        const commentChoices = {};
        document.querySelectorAll( '.rincwc-import-comment-choice' ).forEach( select => {
            commentChoices[ select.dataset.conflictId ] = select.value;
        } );
        return {
            payload,
            force: document.getElementById( 'rincwc-import-force' ).checked,
            overwrite_image_keys: overwriteImageKeys,
            comment_choices: commentChoices,
        };
    }

    function updateProgress( current, total, message ) {
        const progress = document.getElementById( 'rincwc-import-progress' );
        progress.hidden = false;
        progress.querySelector( 'span' ).style.width = ( total ? ( current / total ) * 100 : 100 ) + '%';
        progress.querySelector( 'p' ).textContent = message;
    }

    async function regenerate( jobs ) {
        const failures = [];
        let completed = 0;
        for ( let index = 0; index < jobs.length; index++ ) {
            const job = jobs[ index ];
            const label = job.gallery_title ? `${job.gallery_title} #${job.position}` : `${job.gallery_id}:${job.attach_id}`;
            updateProgress( index, jobs.length, `Generating crop ${index + 1}/${jobs.length} (${label})...` );
            try {
                const crop = await api( 'generate-crops', 'POST', { image_id: job.image_id } );
                if ( crop.status !== 'ok' ) throw new Error( crop.msg || 'Crop generation failed' );

                if ( job.needs_watermark ) {
                    updateProgress( index, jobs.length, `Applying watermark ${index + 1}/${jobs.length} (${label})...` );
                    const watermark = await api( 'apply-watermarks', 'POST', { image_id: job.image_id } );
                    if ( watermark.status !== 'ok' ) throw new Error( watermark.msg || `Watermark result: ${watermark.status}` );
                }

                if ( job.needs_reapprove ) {
                    updateProgress( index, jobs.length, `Restoring approval ${index + 1}/${jobs.length} (${label})...` );
                    const approval = await api( 'approve', 'POST', {
                        image_id: job.image_id,
                        approved_by: job.approved_by,
                        approved_at: job.approved_at,
                        import_token: job.approval_token,
                    } );
                    if ( ! approval.ok ) throw new Error( 'Approval could not be restored' );
                }
                completed++;
            } catch ( error ) {
                failures.push( { image: label, error: errorMessage( error ) } );
            }
            updateProgress( index + 1, jobs.length, `Processed ${index + 1}/${jobs.length} imported selections.` );
        }
        return { completed, failures };
    }

    function renderFinal( result, pipeline ) {
        const final = document.getElementById( 'rincwc-import-final' );
        final.replaceChildren();
        final.hidden = false;
        final.appendChild( element( 'h3', 'Import summary' ) );
        const summary = result.summary;
        final.appendChild( element(
            'p',
            `${summary.images_applied} image rows applied, ${summary.images_skipped} skipped; ${summary.comments_added} comments added, ${summary.comments_imported} conflicts imported, ${summary.comments_kept_local} kept local; ${summary.watermarks_created} watermarks created and ${summary.watermarks_reused} reused.`
        ) );
        final.appendChild( element( 'p', `${pipeline.completed} of ${( result.regenerate || [] ).length} regeneration jobs completed successfully.` ) );

        if ( summary.reattributed_users && summary.reattributed_users.length ) {
            const list = element( 'ul', null, 'rincwc-import-fallbacks' );
            summary.reattributed_users.forEach( fallback => {
                list.appendChild( element( 'li', `${fallback.entity} on ${fallback.image_key}: ${displayLogin( fallback.source_login ) || '(missing login)'} attributed to ${displayLogin( fallback.attributed_to )}.` ) );
            } );
            final.appendChild( list );
        }
        const errors = ( summary.errors || [] ).slice();
        pipeline.failures.forEach( failure => errors.push( `${failure.image}: ${failure.error}` ) );
        if ( errors.length ) {
            final.appendChild( element( 'p', 'Items needing manual attention:', 'rincwc-import-errors' ) );
            const list = element( 'ul', null, 'rincwc-import-errors' );
            errors.forEach( error => list.appendChild( element( 'li', error ) ) );
            final.appendChild( list );
        } else {
            final.appendChild( element( 'p', 'Import completed without errors.' ) );
        }
    }

    async function preview( event ) {
        event.preventDefault();
        const fileInput = document.getElementById( 'rincwc-import-file' );
        if ( ! fileInput.files.length ) return;
        document.getElementById( 'rincwc-import-dry-run' ).disabled = true;
        document.getElementById( 'rincwc-import-actions' ).hidden = true;
        setStatus( 'Reading and validating export…', false );
        try {
            payload = JSON.parse( await fileInput.files[ 0 ].text() );
            diff = await api( 'import/dry-run', 'POST', { payload } );
            renderDiff();
            setStatus( 'Dry run complete. Review the differences below; no data has been changed.', false );
        } catch ( error ) {
            payload = null;
            diff = null;
            document.getElementById( 'rincwc-import-report' ).hidden = true;
            setStatus( 'Preview failed: ' + errorMessage( error ), true );
        } finally {
            document.getElementById( 'rincwc-import-dry-run' ).disabled = false;
        }
    }

    async function applyImport() {
        if ( ! payload || ! diff ) return;
        const button = document.getElementById( 'rincwc-import-apply' );
        button.disabled = true;
        document.getElementById( 'rincwc-import-final' ).hidden = true;
        setStatus( 'Applying database changes…', false );
        try {
            const result = await api( 'import/apply', 'POST', selectedOptions() );
            const jobs = result.regenerate || [];
            setStatus( `Database phase complete. Regenerating ${jobs.length} imported selection${jobs.length === 1 ? '' : 's'}…`, false );
            const pipeline = await regenerate( jobs );
            renderFinal( result, pipeline );
            const hasErrors = ! result.ok || pipeline.failures.length > 0;
            setStatus( hasErrors ? 'Import finished with items needing manual attention.' : 'Import complete.', hasErrors );
        } catch ( error ) {
            setStatus( 'Apply failed: ' + errorMessage( error ), true );
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener( 'DOMContentLoaded', () => {
        const form = document.getElementById( 'rincwc-import-form' );
        const applyButton = document.getElementById( 'rincwc-import-apply' );
        if ( form ) form.addEventListener( 'submit', preview );
        if ( applyButton ) applyButton.addEventListener( 'click', applyImport );
    } );
}());
