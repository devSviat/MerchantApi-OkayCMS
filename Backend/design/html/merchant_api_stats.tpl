{$ma_st = $ma_catalog_stats}
<div class="sviat_ma__count{if $ma_st.sent == 0} is-empty{/if}">
    <span class="sviat_ma__count_num">{$ma_st.sent|escape}</span>
    <span>{$btr->sviat__merchant_api__catalog_count|sprintf:$ma_st.total|escape}</span>
    <span class="sviat_ma__hint">{$btr->sviat__merchant_api__catalog_count_hint|escape}</span>
</div>
{if $ma_st.reasons}
    <details class="sviat_ma__why">
        <summary>{$btr->sviat__merchant_api__catalog_why|escape}</summary>
        <ul>
            {foreach $ma_st.reasons as $ma_reason => $ma_n}
                <li>
                    <b>{$btr->getTranslation("sviat__merchant_api__reason_`$ma_reason`")|escape}</b> — {$ma_n|escape}{if in_array($ma_reason, $ma_run_reasons)} <span class="sviat_ma__hint">{$btr->sviat__merchant_api__catalog_last_run|escape}</span>{/if}
                    {if $ma_st.samples[$ma_reason]}
                        <div class="sviat_ma__samples">
                            {foreach $ma_st.samples[$ma_reason] as $ma_sample}<a href="index.php?controller=ProductAdmin&amp;id={$ma_sample.product_id|escape}" target="_blank" rel="noopener">{$ma_sample.name|escape}</a>{if !$ma_sample@last}, {/if}{/foreach}{if $ma_n > count($ma_st.samples[$ma_reason])} …{/if}
                        </div>
                    {/if}
                </li>
            {/foreach}
        </ul>
    </details>
{/if}
