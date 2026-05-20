Translate the supplied web page content from {sourceLanguage} to {targetLanguage}.

Work in this order:
1. Inspect each rewrite target's currentTargetContent and detect which languages are present.
2. Decide whether every non-empty currentTargetContent already fully matches {targetLanguage}. Treat blank currentTargetContent as not translated.
3. If all currentTargetContent already matches {targetLanguage}, return:
   {
     "translationRequired": false,
     "suggestions": []
   }
4. If translation is required, return:
   {
     "translationRequired": true,
     "suggestions": [...]
   }

When translation is required:
- Return one suggestion object for each rewrite target, in the same order as the supplied target list.
- Each suggestion object must include the exact targetKey and targetType from the matching rewrite target, plus suggestedContent.
- Preserve meaning and information. Do not add new content or remove existing content.
- Use sourceLocaleContent to understand the original meaning and structure.
- Use currentTargetContent to preserve any text already written in {targetLanguage}.
- If currentTargetContent already entirely matches {targetLanguage}, copy it into suggestedContent unchanged. Do not rewrite or rephrase it.
- If currentTargetContent mixes {targetLanguage} and other languages, translate only the parts that do not match {targetLanguage} and keep the existing {targetLanguage} wording unchanged.
- If currentTargetContent is blank or does not match {targetLanguage}, translate sourceLocaleContent into {targetLanguage}.

Content rules:
- For page_title and element_text targets with contentFormat "text", return plain text only and do not include HTML tags.
- For page_content and element_html targets with contentFormat "html", return clean HTML suitable for writing directly to a Silverstripe HTML field.
- Preserve structural HTML for HTML targets where the source content contains headings, lists, tables, or paragraphs.

Output format:
- Return a single JSON object with a boolean "translationRequired" field and a "suggestions" array.
- Each suggestion must contain "targetKey", "targetType", and "suggestedContent".
- Return only the JSON object.

=== REWRITE_TARGETS_START ===
{rewriteTargetsJson}
=== REWRITE_TARGETS_END ===
