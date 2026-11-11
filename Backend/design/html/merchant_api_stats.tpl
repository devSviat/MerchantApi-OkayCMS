{$ma_st = $ma_catalog_stats}
<div class="sviat_ma__count{if $ma_st.sent == 0} is-empty{/if}">
    <span class="sviat_ma__count_num">{$ma_st.sent|escape}</span>
    <span>{$btr->sviat__merchant_api__catalog_count|sprintf:$ma_st.total|escape}</span>
    <span class="sviat_ma__hint">{$btr->sviat__merchant_api__catalog_count_hint|escape}</span>
    {if $ma_st.reasons}
        <span class="sviat_ma__hint sviat_ma__reasons">{$btr->sviat__merchant_api__catalog_not_sent|escape}
            {foreach $ma_st.reasons as $ma_reason => $ma_n}<span class="sviat_ma__reason">{$btr->getTranslation("sviat__merchant_api__reason_`$ma_reason`")|escape} — {$ma_n|escape}{if in_array($ma_reason, $ma_run_reasons)}*{/if}</span>{/foreach}
            {if $ma_has_run_reason}<span class="sviat_ma__reason_note">* {$btr->sviat__merchant_api__catalog_last_run|escape}</span>{/if}
        </span>
    {/if}
</div>
