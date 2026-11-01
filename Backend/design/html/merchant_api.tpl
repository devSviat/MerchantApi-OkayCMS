{$meta_title = $btr->sviat__merchant_api__title scope=global}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->sviat__merchant_api__title|escape}</div>
    </div>
</div>

{if $message_success}
    <div class="alert alert--center alert--icon alert--success">
        <div class="alert__content">
            <div class="alert__title">{$btr->getTranslation("sviat__merchant_api__msg_`$message_success`")|escape}</div>
        </div>
    </div>
{/if}
{if $message_error}
    <div class="alert alert--center alert--icon alert--error">
        <div class="alert__content">
            <div class="alert__title">{$btr->getTranslation("sviat__merchant_api__err_`$message_error`")|escape}</div>
            {if $ma_google_error}<p class="sviat_ma__code">{$ma_google_error|escape}</p>{/if}
            {if $ma_error_langs}<p>{$ma_error_langs|join:', '|escape}</p>{/if}
        </div>
    </div>
{/if}

{if $ma_missing}
    <div class="alert alert--icon alert--error">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__merchant_api__req_title|escape}</div>
            <ul>
                {foreach $ma_missing as $ma_requirement}
                    <li>{$btr->getTranslation($ma_requirement)|escape}</li>
                {/foreach}
            </ul>
        </div>
    </div>
{/if}

{* Готовність: порядок першого налаштування, наступний крок підсвічено. *}
{$ma_next_found = false}
<ol class="sviat_ma__steps" aria-label="{$btr->sviat__merchant_api__steps|escape}">
    {foreach $ma_steps as $ma_step}
        {$ma_is_next = !$ma_step.done && !$ma_next_found}
        {if $ma_is_next}{$ma_next_found = true}{/if}
        <li class="sviat_ma__step{if $ma_step.done} is-done{elseif $ma_is_next} is-next{/if}">
            <a href="{url controller='Sviat.MerchantApi.MerchantApiAdmin' active_tab=$ma_step.tab}"{if $ma_is_next} aria-current="step"{/if}>
                <span class="sviat_ma__step_mark" aria-hidden="true">
                    {if $ma_step.done}<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg>{else}{$ma_step@iteration}{/if}
                </span>
                <span>{$btr->getTranslation("sviat__merchant_api__step_`$ma_step.key`")|escape}</span>
                {if $ma_step.done}<span class="sviat_ma__sr"> — {$btr->sviat__merchant_api__step_done|escape}</span>{/if}
            </a>
        </li>
    {/foreach}
</ol>

<div class="boxed match fn_toggle_wrap tabs">
    <div class="heading_tabs">
        <div class="tab_navigation">
            <a href="#ma_connection" class="heading_box tab_navigation_link">{$btr->sviat__merchant_api__tab_connection|escape}</a>
            <a href="#ma_languages" class="heading_box tab_navigation_link">{$btr->sviat__merchant_api__tab_languages|escape}</a>
            <a href="#ma_sync" class="heading_box tab_navigation_link">{$btr->sviat__merchant_api__tab_sync|escape}</a>
            <a href="#ma_issues" class="heading_box tab_navigation_link">{$btr->sviat__merchant_api__tab_issues|escape}</a>
        </div>
    </div>
    <div class="tab_container">

        {* Підключення: ключ → акаунт → доступ (реєстрація, перевірка) — у тому порядку, у якому їх робити. *}
        <div id="ma_connection" class="tab">
            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__key|escape}</h3>
                {if $ma_key}
                    <dl class="sviat_ma__summary">
                        <dt>{$btr->sviat__merchant_api__key_email|escape}</dt><dd>{$ma_key.client_email|escape}</dd>
                        <dt>{$btr->sviat__merchant_api__key_id|escape}</dt><dd class="sviat_ma__code">{$ma_key.private_key_id|escape}</dd>
                        <dt>{$btr->sviat__merchant_api__key_project|escape}</dt><dd>{$ma_key.project_id|escape}</dd>
                        <dt>{$btr->sviat__merchant_api__key_uploaded|escape}</dt><dd>{$ma_key.uploaded_at|date_format:'%d.%m.%Y %H:%M'|escape}</dd>
                    </dl>
                {else}
                    <p class="sviat_ma__lead">{$btr->sviat__merchant_api__key_none|escape}</p>
                {/if}
                <p class="sviat_ma__hint">{$btr->sviat__merchant_api__key_hint|escape}</p>
                <div class="sviat_ma__buttons">
                    <form method="post" enctype="multipart/form-data" class="sviat_ma__upload">
                        <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                        <input type="hidden" name="action" value="upload_key">
                        <div class="input_file_container">
                            <input name="key_file" class="file_upload input_file" id="sviat_ma_key_file" type="file" accept="application/json,.json"
                                   aria-label="{if $ma_key}{$btr->sviat__merchant_api__key_replace|escape}{else}{$btr->sviat__merchant_api__key_upload|escape}{/if}">
                            <label tabindex="0" for="sviat_ma_key_file" class="input_file_trigger">
                                {include file='svg_icon.tpl' svgId='upload'}
                                <span>{if $ma_key}{$btr->sviat__merchant_api__key_replace|escape}{else}{$btr->button_select_file|escape}{/if}</span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn_small btn_blue">{$btr->sviat__merchant_api__key_save|escape}</button>
                    </form>
                    {if $ma_key}
                        <form method="post">
                            <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                            <input type="hidden" name="action" value="delete_key">
                            <button type="submit" class="btn btn_small btn-danger">{$btr->sviat__merchant_api__key_delete|escape}</button>
                        </form>
                    {/if}
                </div>
                <p class="input_file_return sviat_ma__hint"></p>
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__account|escape}</h3>
                <form method="post" class="sviat_ma__form">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="save_connection">
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_account">{$btr->sviat__merchant_api__account_id|escape}</label>
                        <input class="form-control" id="sviat_ma_account" name="account_id" value="{$ma_account_id|escape}" inputmode="numeric" autocomplete="off" aria-describedby="sviat_ma_account_hint">
                        <p class="sviat_ma__hint" id="sviat_ma_account_hint">{$btr->sviat__merchant_api__account_id_hint|escape}</p>
                    </div>
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_site">{$btr->sviat__merchant_api__site_url|escape}</label>
                        <input class="form-control" id="sviat_ma_site" name="site_url" value="{$ma_site_url|escape}" aria-describedby="sviat_ma_site_hint">
                        <p class="sviat_ma__hint{if !$ma_site_url_saved} text_attention{/if}" id="sviat_ma_site_hint">{if $ma_site_url_saved}{$btr->sviat__merchant_api__site_url_saved_hint|escape}{else}{$btr->sviat__merchant_api__site_url_hint|escape}{/if}</p>
                    </div>
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_countries">{$btr->sviat__merchant_api__countries|escape}</label>
                        <input class="form-control sviat_ma__narrow" id="sviat_ma_countries" name="countries" value="{$ma_countries|escape}" aria-describedby="sviat_ma_countries_hint">
                        <p class="sviat_ma__hint" id="sviat_ma_countries_hint">{$btr->sviat__merchant_api__countries_hint|escape}</p>
                    </div>
                    <div class="sviat_ma__buttons">
                        <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
                    </div>
                </form>
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__access|escape}</h3>
                <p class="sviat_ma__hint">{$btr->sviat__merchant_api__register_hint|escape}</p>
                <form method="post" class="sviat_ma__form">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="register_gcp">
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_dev">{$btr->sviat__merchant_api__developer_email|escape}</label>
                        <div class="sviat_ma__inline_field">
                            <input class="form-control" id="sviat_ma_dev" name="developer_email" type="email" autocomplete="email">
                            <button type="submit" class="btn btn_small btn-secondary">{$btr->sviat__merchant_api__register_button|escape}</button>
                        </div>
                    </div>
                </form>
                <form method="post" class="sviat_ma__check">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn_small btn-secondary">{$btr->sviat__merchant_api__test|escape}</button>
                    <span class="sviat_ma__hint">{if $ma_sources_fetched_at}{$btr->sviat__merchant_api__sources_fetched|escape} {$ma_sources_fetched_at|date_format:'%d.%m.%Y %H:%M'|escape}{else}{$btr->sviat__merchant_api__test_hint|escape}{/if}</span>
                </form>
            </section>
        </div>

        {* Мови й джерела *}
        <div id="ma_languages" class="tab">
            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__languages|escape}</h3>
                <p class="sviat_ma__hint">{$btr->sviat__merchant_api__languages_hint|escape}</p>
                <form method="post" id="sviat_ma_languages_form">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="save_languages">
                    <div class="sviat_ma__scroll">
                        <table class="sviat_ma__table">
                            <thead>
                                <tr>
                                    <th>{$btr->sviat__merchant_api__col_language|escape}</th>
                                    <th>{$btr->sviat__merchant_api__col_send|escape}</th>
                                    <th>{$btr->sviat__merchant_api__col_feed|escape}</th>
                                    <th>{$btr->sviat__merchant_api__col_feed_label|escape}</th>
                                    <th>{$btr->sviat__merchant_api__col_source|escape}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach $ma_languages as $ma_l}
                                    <tr>
                                        <td class="sviat_ma__lang">
                                            {$ma_l.name|escape} <span class="sviat_ma__code">{$ma_l.content_language|escape}</span>
                                            {if $ma_l.removing}<div class="sviat_ma__hint text_attention">{$btr->sviat__merchant_api__removing|escape}</div>{/if}
                                        </td>
                                        <td>
                                            <label class="switch switch-default">
                                                <input class="switch-input" type="checkbox" name="langs[{$ma_l.id|escape}][enabled]" value="1"{if $ma_l.target && !$ma_l.removing} checked{/if}
                                                       aria-label="{$ma_l.name|escape}: {$btr->sviat__merchant_api__col_send|escape}">
                                                <span class="switch-label"></span>
                                                <span class="switch-handle"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <select class="form-control" name="langs[{$ma_l.id|escape}][feed_id]" aria-label="{$ma_l.name|escape}: {$btr->sviat__merchant_api__col_feed|escape}">
                                                {foreach $ma_feeds as $ma_f}
                                                    <option value="{$ma_f->id|escape}"{if $ma_l.target && $ma_l.target.feed_id == $ma_f->id} selected{/if}>{$ma_f->name|escape}</option>
                                                {/foreach}
                                            </select>
                                        </td>
                                        <td>
                                            <input class="form-control" name="langs[{$ma_l.id|escape}][feed_label]" value="{if $ma_l.target}{$ma_l.target.feed_label|escape}{/if}" maxlength="20"
                                                   aria-label="{$ma_l.name|escape}: {$btr->sviat__merchant_api__col_feed_label|escape}">
                                        </td>
                                        <td>
                                            <select class="form-control" name="langs[{$ma_l.id|escape}][data_source]" aria-label="{$ma_l.name|escape}: {$btr->sviat__merchant_api__col_source|escape}">
                                                <option value="">{$btr->sviat__merchant_api__source_none|escape}</option>
                                                {if $ma_l.target && $ma_l.target.data_source && !$ma_l.source_cached}
                                                    <option value="{$ma_l.target.data_source|escape}" selected>{$ma_l.target.data_source|escape}</option>
                                                {/if}
                                                {foreach $ma_sources as $ma_s}
                                                    {if $ma_s.input == 'API' && $ma_s.primary}
                                                        <option value="{$ma_s.name|escape}"{if $ma_l.target && $ma_l.target.data_source == $ma_s.name} selected{/if}>{$ma_s.display_name|escape} ({$ma_s.content_language|default:'*'|escape} / {$ma_s.feed_label|default:'*'|escape})</option>
                                                    {/if}
                                                {/foreach}
                                            </select>
                                            {if !$ma_l.source_ok}<div class="sviat_ma__hint text_warning">{$btr->sviat__merchant_api__source_mismatch|escape}</div>{/if}
                                            {if $ma_l.target && !$ma_l.target.data_source && !$ma_l.removing}
                                                {* Окрема форма поза таблицею: вкладені форми HTML не дозволяє, кнопка прив'язана атрибутом form. *}
                                                <button type="submit" form="sviat_ma_create_{$ma_l.id|escape}" class="btn btn_small btn-secondary sviat_ma__row_action">{$btr->sviat__merchant_api__create_source|escape}</button>
                                            {/if}
                                        </td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                    <div class="sviat_ma__buttons">
                        <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
                    </div>
                </form>
                {foreach $ma_languages as $ma_l}
                    {if $ma_l.target && !$ma_l.target.data_source && !$ma_l.removing}
                        <form method="post" id="sviat_ma_create_{$ma_l.id|escape}" hidden>
                            <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                            <input type="hidden" name="action" value="create_source">
                            <input type="hidden" name="lang_id" value="{$ma_l.id|escape}">
                        </form>
                    {/if}
                {/foreach}
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__existing_sources|escape}</h3>
                {if $ma_sources}
                    <p class="sviat_ma__hint">{$btr->sviat__merchant_api__existing_sources_hint|escape} {$btr->sviat__merchant_api__sources_fetched|escape} {$ma_sources_fetched_at|date_format:'%d.%m.%Y %H:%M'|escape}</p>
                    <div class="sviat_ma__scroll">
                        <table class="sviat_ma__table">
                            <thead><tr><th>{$btr->sviat__merchant_api__col_name|escape}</th><th>{$btr->sviat__merchant_api__col_input|escape}</th><th>{$btr->sviat__merchant_api__col_language|escape}</th><th>{$btr->sviat__merchant_api__col_feed_label|escape}</th><th>{$btr->sviat__merchant_api__countries|escape}</th></tr></thead>
                            <tbody>
                                {foreach $ma_sources as $ma_s}
                                    <tr>
                                        <td>{$ma_s.display_name|escape}<div class="sviat_ma__code">{$ma_s.name|escape}</div></td>
                                        <td>{$ma_s.input|escape}</td>
                                        <td>{$ma_s.content_language|default:'—'|escape}</td>
                                        <td>{$ma_s.feed_label|default:'—'|escape}</td>
                                        <td>{$ma_s.countries|join:', '|escape}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {else}
                    <p class="sviat_ma__hint">{$btr->sviat__merchant_api__sources_none|escape}</p>
                {/if}
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__price_label|escape}</h3>
                <p class="sviat_ma__hint">{$btr->sviat__merchant_api__price_label_hint|escape}</p>
                <form method="post" class="sviat_ma__form">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="save_price_label">
                    <div class="okay_switch clearfix sviat_ma__switch">
                        <label class="switch_label" for="sviat_ma_pl_on">{$btr->sviat__merchant_api__price_label_on|escape}</label>
                        <label class="switch switch-default">
                            <input class="switch-input" id="sviat_ma_pl_on" type="checkbox" name="price_label_enabled" value="1"{if $ma_price_label.index !== ''} checked{/if}>
                            <span class="switch-label"></span>
                            <span class="switch-handle"></span>
                        </label>
                    </div>
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_pl_index">{$btr->sviat__merchant_api__price_label_index|escape}</label>
                        <select class="form-control sviat_ma__narrow" id="sviat_ma_pl_index" name="price_label_index">
                            {foreach [0,1,2,3,4] as $ma_i}
                                <option value="{$ma_i}"{if $ma_price_label.index === "`$ma_i`"} selected{/if}>custom_label_{$ma_i}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_pl_bounds">{$btr->sviat__merchant_api__price_label_bounds|escape}</label>
                        <input class="form-control" id="sviat_ma_pl_bounds" name="price_label_bounds" value="{$ma_price_label.bounds|escape}" placeholder="100, 200, 400, 600, 800, 1000" aria-describedby="sviat_ma_pl_hint">
                        <p class="sviat_ma__hint" id="sviat_ma_pl_hint">{$btr->sviat__merchant_api__price_label_bounds_hint|escape}</p>
                    </div>
                    <div class="sviat_ma__buttons">
                        <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
                    </div>
                </form>
            </section>
        </div>

        {* Синхронізація *}
        <div id="ma_sync" class="tab">
            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__mode|escape}</h3>
                <form method="post" class="sviat_ma__form sviat_ma__form--wide">
                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                    <input type="hidden" name="action" value="save_sync">
                    <fieldset class="sviat_ma__modes">
                        <legend class="sviat_ma__sr">{$btr->sviat__merchant_api__mode|escape}</legend>
                        {foreach ['paused', 'dry', 'live'] as $ma_m}
                            <div class="okay_type_radio_wrap">
                                <input id="sviat_ma_mode_{$ma_m}" class="hidden_check" name="mode" type="radio" value="{$ma_m}"{if $ma_mode == $ma_m} checked{/if}>
                                <label for="sviat_ma_mode_{$ma_m}" class="okay_type_radio">
                                    <span class="sviat_ma__mode">
                                        <strong>{$btr->getTranslation("sviat__merchant_api__mode_`$ma_m`")|escape}</strong>
                                        <span class="sviat_ma__hint">{$btr->getTranslation("sviat__merchant_api__mode_`$ma_m`_hint")|escape}</span>
                                    </span>
                                </label>
                            </div>
                        {/foreach}
                    </fieldset>
                    <div class="sviat_ma__field">
                        <label class="heading_label" for="sviat_ma_ops">{$btr->sviat__merchant_api__ops_per_run|escape}</label>
                        <input class="form-control sviat_ma__narrow" id="sviat_ma_ops" name="ops_per_run" type="number" min="1" max="5000" value="{$ma_ops_per_run|escape}" aria-describedby="sviat_ma_ops_hint">
                        <p class="sviat_ma__hint" id="sviat_ma_ops_hint">{$btr->sviat__merchant_api__ops_per_run_hint|escape}</p>
                    </div>
                    <div class="sviat_ma__buttons">
                        <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
                    </div>
                </form>
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__state|escape}</h3>
                <div class="sviat_ma__scroll">
                    <table class="sviat_ma__table">
                        <thead><tr><th>{$btr->sviat__merchant_api__col_language|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_known|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_sent|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_failed|escape}</th><th></th></tr></thead>
                        <tbody>
                            {foreach $ma_languages as $ma_l}
                                {if $ma_l.target}
                                    <tr>
                                        <td>{$ma_l.name|escape}</td>
                                        <td class="is-num">{$ma_l.items|escape}</td>
                                        <td class="is-num">{$ma_l.sent|escape}</td>
                                        <td class="is-num">{$ma_l.failed|escape}</td>
                                        <td>
                                            {if $ma_l.blocked_deletes}
                                                <form method="post" class="sviat_ma__check">
                                                    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                                                    <input type="hidden" name="action" value="confirm_deletes">
                                                    <input type="hidden" name="lang_id" value="{$ma_l.id|escape}">
                                                    <input type="hidden" name="count" value="{$ma_l.blocked_deletes|escape}">
                                                    <span class="text_attention">{$btr->sviat__merchant_api__blocked_deletes|sprintf:$ma_l.blocked_deletes|escape}</span>
                                                    <button type="submit" class="btn btn_small btn-danger">{$btr->sviat__merchant_api__confirm_deletes|escape}</button>
                                                </form>
                                            {/if}
                                        </td>
                                    </tr>
                                {/if}
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sviat_ma__section">
                <h3 class="sviat_ma__h">{$btr->sviat__merchant_api__runs|escape}</h3>
                {if $ma_runs}
                    <div class="sviat_ma__scroll">
                        <table class="sviat_ma__table">
                            <thead><tr>
                                <th>{$btr->sviat__merchant_api__col_started|escape}</th><th>{$btr->sviat__merchant_api__col_language|escape}</th><th>{$btr->sviat__merchant_api__col_status|escape}</th>
                                <th class="is-num">{$btr->sviat__merchant_api__col_built|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_sent|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_deleted|escape}</th>
                                <th class="is-num">{$btr->sviat__merchant_api__col_failed|escape}</th><th class="is-num">{$btr->sviat__merchant_api__col_deferred|escape}</th><th>{$btr->sviat__merchant_api__col_details|escape}</th>
                            </tr></thead>
                            <tbody>
                                {foreach $ma_runs as $ma_r}
                                    <tr>
                                        <td class="sviat_ma__nowrap">{$ma_r->started_at|date_format:'%d.%m.%Y %H:%M'|escape}</td>
                                        <td>{$ma_lang_names[$ma_r->lang_id]|default:$ma_r->lang_id|escape}</td>
                                        <td><span class="sviat_ma__status sviat_ma__status--{$ma_r->status|escape}">{$btr->getTranslation("sviat__merchant_api__status_`$ma_r->status`")|escape}</span></td>
                                        <td class="is-num">{$ma_r->built|escape}</td>
                                        <td class="is-num">{$ma_r->sent|escape}</td>
                                        <td class="is-num">{$ma_r->deleted|escape}</td>
                                        <td class="is-num">{$ma_r->failed|escape}</td>
                                        <td class="is-num">{$ma_r->deferred|escape}</td>
                                        <td class="sviat_ma__details">
                                            {if $ma_r->summary_data.error}<div>{$btr->getTranslation("sviat__merchant_api__setup_`$ma_r->summary_data.error`")|escape}</div>{/if}
                                            {if $ma_r->summary_data.stopped}<div class="sviat_ma__code">{$ma_r->summary_data.stopped|escape}</div>{/if}
                                            {if $ma_r->summary_data.counts}<div>{foreach $ma_r->summary_data.counts as $ma_reason => $ma_n}{$ma_reason|escape}: {$ma_n|escape}{if !$ma_n@last}, {/if}{/foreach}</div>{/if}
                                            {if $ma_r->blocked_deletes}<div class="text_attention">{$btr->sviat__merchant_api__blocked_deletes|sprintf:$ma_r->blocked_deletes|escape}</div>{/if}
                                            {if $ma_r->summary_data.errors}{foreach $ma_r->summary_data.errors as $ma_offer => $ma_e}<div>#{$ma_offer|escape}: {$ma_e|escape}</div>{/foreach}{/if}
                                            {if $ma_r->summary_data.invalid}{foreach $ma_r->summary_data.invalid as $ma_offer => $ma_why}<div>#{$ma_offer|escape}: {$ma_why|join:', '|escape}</div>{/foreach}{/if}
                                        </td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {else}
                    <p class="sviat_ma__hint">{$btr->sviat__merchant_api__runs_none|escape}</p>
                {/if}
            </section>
        </div>

        {* Проблеми товарів *}
        <div id="ma_issues" class="tab">
            <section class="sviat_ma__section">
                {if $ma_status.error}
                    <p class="text_warning">{$btr->sviat__merchant_api__status_error|escape} <span class="sviat_ma__code">{$ma_status.error|escape}</span></p>
                {elseif $ma_status.polled_at}
                    <p class="sviat_ma__lead">{$btr->sviat__merchant_api__status_summary|sprintf:$ma_status.products:$ma_status.disapproved:$ma_status.demoted|escape}</p>
                    <p class="sviat_ma__hint">{$btr->sviat__merchant_api__status_polled|escape} {$ma_status.polled_at|date_format:'%d.%m.%Y %H:%M'|escape}</p>
                {else}
                    <p class="sviat_ma__lead">{$btr->sviat__merchant_api__status_never|escape}</p>
                {/if}
                {if $ma_issues}
                    <div class="sviat_ma__scroll">
                        <table class="sviat_ma__table">
                            <thead><tr><th>{$btr->sviat__merchant_api__col_product|escape}</th><th>{$btr->sviat__merchant_api__col_language|escape}</th><th>{$btr->sviat__merchant_api__col_severity|escape}</th><th>{$btr->sviat__merchant_api__col_issues|escape}</th></tr></thead>
                            <tbody>
                                {foreach $ma_issues as $ma_is}
                                    <tr>
                                        <td>{if $ma_is->product_id}<a href="{url controller=ProductAdmin id=$ma_is->product_id}">#{$ma_is->offer_id|escape}</a>{else}#{$ma_is->offer_id|escape}{/if}</td>
                                        <td>{$ma_is->content_language|escape} / {$ma_is->feed_label|escape}</td>
                                        <td><span class="sviat_ma__status sviat_ma__status--{if $ma_is->severity == 'DISAPPROVED'}failed{else}partial{/if}">{$btr->getTranslation("sviat__merchant_api__severity_`$ma_is->severity`")|escape}</span></td>
                                        <td>{foreach $ma_is->issues_list as $ma_issue}<div class="sviat_ma__issue"><strong>{$ma_issue.description|escape}</strong>{if $ma_issue.attribute} <span class="sviat_ma__code">{$ma_issue.attribute|escape}</span>{/if}{if $ma_issue.detail}<div class="sviat_ma__hint">{$ma_issue.detail|escape}</div>{/if}</div>{/foreach}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {/if}
            </section>
        </div>
    </div>
</div>
