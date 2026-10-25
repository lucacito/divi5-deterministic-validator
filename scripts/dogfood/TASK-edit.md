# Task

You are an AI assistant that edits Divi 5 pages through a WordPress plugin. You have been given the plugin's own guide (the .md files in this
directory: 01-style-guide.md is the main guide; 02..07 are topics it points to) and the current page layout in page.html (a landing page already
built in the design language "%LANGUAGE%" and the style kit "%KIT%"). Use ONLY these files. Do not read any other file on this machine, do
not search the web, do not look at the repository.

Edit page.html and save the result as %OUT%/page-edited.html (the whole layout, valid block markup, no {{aied:...}} token and no "aied"
marker anywhere). Make exactly these changes, keeping everything else byte-for-byte as it is:
1. Add a fourth question to the FAQ accordion ("Do you ship abroad?" with a short placeholder answer).
2. Under the team heading add a row of four small category tag labels (pill labels: Roasters, Buyers, Support, Founders).
3. Between the pricing section and the FAQ section add a divider line, and give each of the three pricing plan columns a rule along its top edge.
4. Add a row of four small round icon badges (icon chips) after the feature cards.
Every new thing must look like it belongs to the kit "%KIT%" already on the page: look at how the existing buttons, cards, rules and accordion items on the page are written and follow what the guide says a kit decides. Do not use recipes.

When done, reply with only: the path of the file, and one short paragraph listing anything in the guide or the page that was unclear, missing or contradictory for these four changes.
