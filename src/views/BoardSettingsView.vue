<template>
	<div class="pw-view">
		<router-link class="pw-crumb" :to="{ name: ausUebersicht ? 'board-overview' : 'board', params: { boardId: String(boardId) } }">
			<ChevronLeftIcon :size="16" />{{ crumbLabel }}
		</router-link>

		<div class="pw-view__head">
			<div class="pw-view__ident">
				<h2>{{ t('projektwerk', 'Einstellungen') }}</h2>
				<div v-if="projectLine" class="pw-view__org">
					{{ projectLine }}
				</div>
			</div>
		</div>

		<!--
			Die Verwaltung steht nur internen Mitgliedern mit Verwaltungsrecht
			offen (§8). Der Server weist jeden Schreibversuch ohnehin mit 403 ab
			— hier faellt nur die Bedienung weg, damit niemand ein Formular
			ausfuellt, das er nicht abschicken darf.
		-->
		<NcEmptyContent
			v-if="!store.loading && !mayConfigureBoard"
			:name="t('projektwerk', 'Keine Berechtigung')"
			:description="t('projektwerk', 'Projekteinstellungen pflegen interne Mitglieder mit Verwaltungsrecht.')">
			<template #icon>
				<LockIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else-if="mayConfigureBoard && projectBoardsLoaded" class="pw-settingspage__body">
			<!--
				Linke Bereichs-Navigation wie in „Meine Einstellungen" (#196
				Teil 2): dieselben `pw-subnav`/`pw-settingspage`-Klassen, damit
				beide Einstellungsseiten gleich aussehen.
			-->
			<PwSettingsNav
				:groups="navGroups"
				:modelValue="activeSection"
				@update:modelValue="selectSection">
				<template #group-board>
					<div v-if="showBoardPicker" class="pw-subnav__picker">
						<NcSelect
							inputId="pw-set-boardpick"
							:ariaLabelCombobox="t('projektwerk', 'Board wählen')"
							:options="boardOptions"
							:modelValue="selectedBoardOption"
							:clearable="false"
							@update:modelValue="switchSettingsBoard" />
					</div>
				</template>
			</PwSettingsNav>

			<div class="pw-settingspage__content pw-settings">
				<section v-if="mayManage" v-show="activeSection === 'projekt'" class="pw-settings__block">
					<div class="pw-form-section">
						<h3>{{ t('projektwerk', 'Projekt') }}</h3>

						<div class="pw-feld">
							<label class="pw-feld__label" for="pw-set-ptitle">{{ t('projektwerk', 'Projektname') }}</label>
							<input
								id="pw-set-ptitle"
								v-model="board.projectTitle"
								class="pw-input"
								type="text">
						</div>

						<div class="pw-feld">
							<label class="pw-feld__label" for="pw-set-pdesc">{{ t('projektwerk', 'Beschreibung') }}</label>
							<textarea
								id="pw-set-pdesc"
								v-model="board.projectDescription"
								class="pw-input"
								rows="3" />
						</div>
					</div>

					<!--
					Beide Firmennamen stehen gleichberechtigt nebeneinander.
					Truege nur die Kundenseite eine Firma, waere die interne
					stumm „der Normalfall" (§8).
				-->
					<template v-if="mayManage">
						<div class="pw-form-section">
							<h3>{{ t('projektwerk', 'Beteiligte Firmen') }}</h3>
							<div class="pw-feld-reihe">
								<div class="pw-feld">
									<label class="pw-feld__label" for="pw-set-orgi">{{ t('projektwerk', 'Eigene Firma') }}</label>
									<input
										id="pw-set-orgi"
										v-model="board.orgInternal"
										class="pw-input"
										type="text">
								</div>
								<!--
								Der Kunde des Projekts (#309) — filterbares Label, wofür das
								Projekt läuft. Auswahl mit vorhandenen Kunden (datalist) oder
								frei getippt. Nicht zu verwechseln mit der Firma je Mitglied:
								Beteiligte können aus anderen Firmen kommen (z. B. Subunternehmer).
							-->
								<div class="pw-feld">
									<label class="pw-feld__label" for="pw-set-customer">{{ t('projektwerk', 'Kunde') }}</label>
									<input
										id="pw-set-customer"
										v-model="board.customer"
										class="pw-input"
										list="pw-customer-suggestions"
										:placeholder="t('projektwerk', 'Kunde (optional)')">
									<datalist id="pw-customer-suggestions">
										<option v-for="c in store.customerSuggestions" :key="c" :value="c" />
									</datalist>
								</div>
							</div>
						</div>

						<div class="pw-form-section">
							<h3>{{ t('projektwerk', 'Zusammenarbeit') }}</h3>
							<div class="pw-feld">
								<label class="pw-feld__label" for="pw-set-chat">{{ t('projektwerk', 'Adresse des Projektchats') }}</label>
								<input
									id="pw-set-chat"
									v-model="board.chatUrl"
									class="pw-input"
									type="url">
								<!-- Ohne Adresse entfaellt der Knopf ersatzlos (§9). -->
								<span class="pw-feld__hinweis">
									{{ t('projektwerk', 'Leer lassen blendet den Knopf „Zum Projektchat“ aus.') }}
								</span>
							</div>

							<!--
							#281: Der Projekt-Schalter. Ist er an, dürfen alle
							Mitglieder (auch externe) hier weitere Boards anlegen und
							einrichten (Arbeitsgruppen). Aus = nur der Manager.
						-->
							<div class="pw-feld">
								<NcCheckboxRadioSwitch
									v-model="board.memberBoardsAllowed"
									type="switch">
									{{ t('projektwerk', 'Mitglieder dürfen eigene Boards in diesem Projekt anlegen') }}
								</NcCheckboxRadioSwitch>
								<span class="pw-feld__hinweis">
									{{ t('projektwerk', 'Damit können Arbeitsgruppen selbst ein Board anlegen und dessen Spalten pflegen. Alle Projektmitglieder sehen weiterhin alle Boards.') }}
								</span>
							</div>
						</div>
					</template>

					<div class="pw-viscontrol__actions">
						<NcButton variant="primary" :disabled="busy || board.projectTitle.trim() === ''" @click="saveProject">
							{{ t('projektwerk', 'Speichern') }}
						</NcButton>
					</div>
				</section>

				<section v-if="!singleBoard" v-show="activeSection === 'board'" class="pw-settings__block">
					<div class="pw-form-section">
						<h3>{{ t('projektwerk', 'Board') }}</h3>

						<div class="pw-feld">
							<label class="pw-feld__label" for="pw-set-title">{{ t('projektwerk', 'Boardname') }}</label>
							<input
								id="pw-set-title"
								v-model="board.title"
								class="pw-input"
								type="text">
						</div>

						<div class="pw-feld">
							<label class="pw-feld__label" for="pw-set-desc">{{ t('projektwerk', 'Beschreibung') }}</label>
							<textarea
								id="pw-set-desc"
								v-model="board.description"
								class="pw-input"
								rows="3" />
						</div>
					</div>

					<div class="pw-viscontrol__actions">
						<NcButton variant="primary" :disabled="busy || board.title.trim() === ''" @click="saveBoardGeneral">
							{{ t('projektwerk', 'Speichern') }}
						</NcButton>
					</div>
				</section>

				<!--
				**GitHub-Anbindung** (#12) — eigener Bereich (#196 Teil 2), nur für
				Softwareprojekte. Der Schalter blendet die Überführungs-Aktion an
				den Vorgängen dieses Boards ein; das Repo sagt, wohin überführte
				Issues gehen. Der persönliche Token liegt getrennt in „Meine
				Einstellungen", nicht am Board.
			-->
				<section v-show="activeSection === 'github'" class="pw-settings__block">
					<h3 class="pw-col__head">
						GitHub
					</h3>

					<div class="pw-field">
						<label class="pw-settings__check">
							<input
								type="checkbox"
								:checked="board.githubEnabled"
								:disabled="busy"
								@change="board.githubEnabled = ($event.target as HTMLInputElement).checked">
							{{ t('projektwerk', 'GitHub-Anbindung für dieses Board') }}
						</label>
					</div>

					<div v-if="board.githubEnabled" class="pw-field">
						<label for="pw-set-ghrepo">{{ t('projektwerk', 'Ziel-Repository') }}</label>
						<!--
						Tippen sucht in den Repos, auf die der eigene Token
						Zugriff hat (#196) — die Treffer darunter zum Auswählen.
						Das Feld bleibt zugleich der Wert: Wer den Namen kennt oder
						keinen Token hinterlegt hat, tippt „owner/repo" von Hand.
					-->
						<!--
						`@focusin` (nicht `@focus`) am Wrapper: Der Fokus liegt am
						inneren Input von NcTextField; `focusin` bubbelt zuverlässig
						hoch, `focus` nicht. Beim Reinklicken erscheinen so gleich
						die ersten Repos — ohne dass man erst tippen muss (#196).
					-->
						<div @focusin="repoFokus">
							<NcTextField
								id="pw-set-ghrepo"
								v-model="board.githubRepo"
								:labelOutside="true"
								:label="t('projektwerk', 'Ziel-Repository')"
								placeholder="owner/repo"
								@update:modelValue="repoSuchen" />
						</div>

						<div v-if="repoHits.length > 0" class="pw-settings__hits">
							<button
								v-for="repo in repoHits"
								:key="repo"
								type="button"
								class="pw-settings__hit"
								:aria-pressed="board.githubRepo === repo"
								@click="repoWaehlen(repo)">
								<span class="pw-person__name">{{ repo }}</span>
							</button>
						</div>
						<span v-if="repoSuchtLaeuft" class="pw-settings__hint">
							{{ t('projektwerk', 'Suche läuft…') }}
						</span>
						<span v-else-if="repoSuchFehler !== ''" class="pw-settings__hint">
							{{ repoSuchFehler }}
						</span>

						<span class="pw-settings__hint">
							{{ t('projektwerk', 'Im Format „owner/repo“, z. B. „cpcMomentum/projektwerk“. Tippen sucht in Ihren GitHub-Repositorys — dazu muss ein Token in „Meine Einstellungen“ hinterlegt sein. Leer lassen lässt die Überführung ausgeblendet, bis ein Ziel eingetragen ist.') }}
						</span>
					</div>

					<div class="pw-viscontrol__actions">
						<NcButton variant="primary" :disabled="busy" @click="saveGithub">
							{{ t('projektwerk', 'Speichern') }}
						</NcButton>
					</div>
				</section>

				<!--
				Dateiablage.

				**Der Ablageort IST die Sichtbarkeit** (§5.18) — deshalb zwei
				Ordner und nicht einer mit Unterordnern. Was in „Austausch"
				liegt, sieht die Kundenseite; was im internen Ordner liegt,
				nicht. Dass Nextcloud diese Trennung durchsetzt und nicht die
				App, ist der Grund, warum sie trägt.

				Anders als die Felder darüber wird hier **sofort** geschrieben:
				Einen Ordner auszuwählen ist bereits die Bestätigung, ein
				„Speichern" danach wäre eine zweite für dieselbe Entscheidung.
			-->
				<section v-show="activeSection === 'dateiablage'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Dateiablage') }}
					</h3>

					<p class="pw-settings__hint">
						{{ t('projektwerk', 'Anhänge an Vorgängen landen in diesen Ordnern. Ohne Ordner sind an den betreffenden Vorgängen keine Anhänge möglich.') }}
					</p>

					<div v-for="slot in folderSlots" :key="slot.key" class="pw-feld">
						<label class="pw-feld__label" :for="`pw-set-${slot.key}`">{{ slot.label }}</label>
						<div class="pw-settings__row">
							<NcTextField
								:id="`pw-set-${slot.key}`"
								v-model="folderDrafts[slot.key]"
								:labelOutside="true"
								:label="slot.label"
								:placeholder="slot.placeholder"
								:disabled="busy || !mayManage"
								@keydown.enter="saveFolder(slot.key)" />

							<!--
							Der Wähler ist der eigentliche Weg (#139): auswählen oder
							anlegen, ohne einen Pfad abzutippen. Das Textfeld bleibt
							daneben stehen — es zeigt den gesetzten Ordner und dient
							als Rückfallweg.
						-->
							<NcButton
								:disabled="busy || !mayManage"
								@click="openPicker(slot.key)">
								<template #icon>
									<FolderIcon :size="20" />
								</template>
								{{ t('projektwerk', 'Ordner wählen') }}
							</NcButton>

							<NcButton
								:disabled="busy || !mayManage || folderDrafts[slot.key] === slot.path"
								@click="saveFolder(slot.key)">
								{{ t('projektwerk', 'Übernehmen') }}
							</NcButton>
						</div>
						<span class="pw-feld__hinweis">{{ slot.hint }}</span>
					</div>
				</section>

				<section v-show="activeSection === 'spalten'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Spalten') }}
					</h3>

					<div v-for="(column, index) in store.columns" :key="column.id" class="pw-settings__row">
						<!--
						Ein Entwurf je Spalte, gespeichert erst bei Enter oder
						beim Verlassen des Feldes. Direkt am Tastendruck zu
						speichern hiesse ein PATCH je Zeichen — und weil
						`write()` waehrend eines laufenden Aufrufs weitere
						verwirft, gingen Anschlaege verloren und das Feld spraenge
						auf den zuletzt gespeicherten Stand zurueck.
					-->
						<NcTextField
							:modelValue="draftFor(column)"
							:labelOutside="true"
							:aria-label="t('projektwerk', 'Name der Spalte')"
							:placeholder="t('projektwerk', 'Name der Spalte')"
							@update:modelValue="columnDrafts[column.id] = $event"
							@keydown.enter="commitColumn(column)"
							@blur="commitColumn(column)" />
						<!--
							**Endspalte** (#172): Trägt die Spalte ein Ergebnis, bietet
							die App beim Hineinziehen einer Karte „Auch abschließen?" an.
							Ein schlichtes Auswahlfeld — keine/erledigt/verworfen —, weil
							es genau drei feste Zustände sind.
						-->
						<label class="hidden-visually" :for="'pw-col-outcome-' + column.id">
							{{ t('projektwerk', 'Endspalte') }}
						</label>
						<select
							:id="'pw-col-outcome-' + column.id"
							class="pw-col__outcome"
							:value="column.finalOutcome ?? ''"
							:disabled="busy"
							@change="setColumnOutcome(column, $event)">
							<option value="">
								{{ t('projektwerk', 'Keine Endspalte') }}
							</option>
							<option value="done">
								{{ t('projektwerk', 'Endspalte: erledigt') }}
							</option>
							<option value="discarded">
								{{ t('projektwerk', 'Endspalte: verworfen') }}
							</option>
						</select>
						<!--
						Hoch und runter statt Ziehen: Jede Zieh-Geste braucht eine
						Alternative ohne Ziehen, und hier ist sie der einzige Weg
						— Tastatur und Screenreader sind Abnahmekriterium.
					-->
						<NcButton
							:disabled="busy || index === 0"
							:aria-label="t('projektwerk', 'Spalte nach oben')"
							@click="moveColumn(index, -1)">
							<template #icon>
								<ArrowUpIcon :size="20" />
							</template>
						</NcButton>
						<NcButton
							:disabled="busy || index === store.columns.length - 1"
							:aria-label="t('projektwerk', 'Spalte nach unten')"
							@click="moveColumn(index, 1)">
							<template #icon>
								<ArrowDownIcon :size="20" />
							</template>
						</NcButton>
						<!--
						Nur der Eigentuemer, nicht jeder mit Verwaltungsrecht:
						Der Vorgang fasst Daten aller Beteiligten an, auch die
						hier unsichtbaren. Die letzte Spalte bleibt stehen — es
						gaebe kein Ziel.
					-->
						<!--
						Der Name gehoert in die Beschriftung: Ohne ihn stehen
						sechs Knoepfe „Spalte entfernen" untereinander, und wer
						die Seite hoert statt sieht, kann sie nicht
						auseinanderhalten — bei einem Knopf, der eine Spalte
						abraeumt, ist das keine Feinheit.
					-->
						<NcButton
							v-if="mayRemoveColumns"
							:disabled="busy || store.columns.length < 2"
							:aria-label="removeLabelFor(column)"
							@click="askRemoveColumn(column)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</div>

					<div class="pw-settings__row">
						<NcTextField
							v-model="newColumn"
							:labelOutside="true"
							:aria-label="t('projektwerk', 'Neue Spalte')"
							:placeholder="t('projektwerk', 'Neue Spalte')"
							@keydown.enter="addColumn" />
						<NcButton :disabled="busy || newColumn.trim() === ''" @click="addColumn">
							{{ t('projektwerk', 'Hinzufügen') }}
						</NcButton>
					</div>
				</section>

				<section v-show="activeSection === 'mitglieder'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Mitglieder') }}
					</h3>

					<div v-for="member in store.members" :key="member.userId" class="pw-settings__member">
						<NcAvatar
							:user="member.userId"
							:displayName="member.resolvedName"
							:size="32"
							:disableMenu="true"
							:hideStatus="true" />

						<span class="pw-person__body">
							<span class="pw-person__name">{{ member.resolvedName }}</span>
							<!--
							Die Kennung als Zweitzeile hilft, gleichnamige Personen
							zu unterscheiden — aber nur, wenn sie lesbar ist. Ein
							Gast-Konto trägt als Kennung einen 64-stelligen Hash;
							der sagt niemandem etwas und wird deshalb weggelassen.
							Der Anzeigename oben steht ohnehin (resolvedName).
						-->
							<span v-if="handleFor(member) !== ''" class="pw-person__org">{{ handleFor(member) }}</span>
						</span>

						<select
							:value="member.role"
							:aria-label="t('projektwerk', 'Rolle')"
							:disabled="busy || isOwner(member)"
							@change="changeRole(member, $event)">
							<option value="internal">
								{{ t('projektwerk', 'Intern') }}
							</option>
							<option value="external">
								{{ t('projektwerk', 'Kundenseite') }}
							</option>
						</select>

						<!--
						Firma je Mitglied (#309): inline wie die Rolle. Vorbelegt beim
						Anlegen (intern = eigene Firma, extern = Kunde), hier
						überschreibbar — z. B. ein Subunternehmer auf Kundenseite mit
						eigener Firma. Auswahl mit vorhandenen Firmen oder frei getippt.
					-->
						<input
							:value="member.company || ''"
							class="pw-plaininput pw-settings__memcompany"
							list="pw-company-suggestions"
							:aria-label="t('projektwerk', 'Firma')"
							:placeholder="t('projektwerk', 'Firma')"
							:disabled="busy"
							@change="changeCompany(member, $event)">

						<!--
						Das Verwaltungsrecht gibt es nur fuer interne Mitglieder,
						und der Eigentuemer behaelt es immer (§8). Beides steht
						auch im Dienst — hier faellt nur der Schalter weg.
					-->
						<label class="pw-settings__check">
							<input
								type="checkbox"
								:checked="member.isManager"
								:disabled="busy || member.role === 'external' || isOwner(member)"
								@change="changeManager(member, $event)">
							{{ t('projektwerk', 'Verwaltung') }}
						</label>

						<!--
						Entfernen (§5.29): Der Eigentuemer laesst sich nicht
						entfernen (er behaelt das Verwaltungsrecht). Die
						Rueckfrage nennt die Zahl der privaten Vorgaenge, die
						dabei geloescht werden.
					-->
						<NcButton
							variant="tertiary"
							:disabled="busy || isOwner(member)"
							:aria-label="t('projektwerk', 'Mitglied entfernen')"
							@click="askRemoveMember(member)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</div>

					<datalist id="pw-company-suggestions">
						<option v-for="c in store.companySuggestions" :key="c" :value="c" />
					</datalist>

					<div class="pw-settings__row">
						<!--
						Eigener Endpunkt statt Nextclouds Personensuche: Die
						liefert in Gast-Sitzungen eine leere Liste, und ein
						Aufruf mit dieser Eigenschaft waere irgendwann an einer
						Stelle kopiert, wo Gaeste hinkommen.
					-->
						<NcTextField
							v-model="memberSearch"
							:labelOutside="true"
							:aria-label="t('projektwerk', 'Person suchen')"
							:placeholder="t('projektwerk', 'Person suchen')"
							@update:modelValue="searchPeople" />
						<select v-model="newMemberRole" :aria-label="t('projektwerk', 'Rolle')">
							<option value="internal">
								{{ t('projektwerk', 'Intern') }}
							</option>
							<option value="external">
								{{ t('projektwerk', 'Kundenseite') }}
							</option>
						</select>
						<NcButton :disabled="busy || newMember === ''" @click="addMemberToBoard">
							{{ t('projektwerk', 'Hinzufügen') }}
						</NcButton>
					</div>

					<div v-if="candidates.length > 0" class="pw-settings__hits">
						<button
							v-for="person in candidates"
							:key="person.userId"
							type="button"
							class="pw-settings__hit"
							:aria-pressed="newMember === person.userId"
							@click="newMember = person.userId">
							<!--
							Name plus E-Mail zur Unterscheidung (#309): zwei Konten
							mit gleichem Anzeigenamen sind sonst nicht auseinander-
							zuhalten. Die interne Konto-ID (bei Gästen ein Hash)
							erscheint NICHT mehr; Gäste sind als „Gast" markiert
							(F2 — ihre System-E-Mail ist die Einladungsadresse).
						-->
							<span class="pw-person__name">{{ person.displayName }}</span>
							<span v-if="person.email || person.isGuest" class="pw-person__org">
								{{ person.email || t('projektwerk', 'Gast') }}
							</span>
						</button>
					</div>
					<span v-else-if="memberSearch.trim() !== '' && !searching" class="pw-settings__hint">
						{{ t('projektwerk', 'Keine passenden Konten gefunden.') }}
					</span>
					<span class="pw-settings__hint">
						{{ t('projektwerk', 'Personenweise, keine Gruppen — die Rolle hängt an der Mitgliedschaft, nicht am Nextcloud-Konto.') }}
					</span>
					<!--
					Die Suche findet nur bestehende Konten. Ohne diesen Satz
					sucht jemand eine Kundin, die es in Nextcloud noch gar nicht
					gibt, bekommt „Keine passenden Konten gefunden." und raet,
					woran es liegt. Die App legt bewusst keine Konten an — sie
					haengt an keiner anderen App —, aber sie kann sagen, wo sie
					herkommen (#74).
				-->
					<span class="pw-settings__hint">
						{{ t('projektwerk', 'Gefunden werden nur bestehende Nextcloud-Konten. Wer noch keines hat — etwa auf Kundenseite — braucht zuerst eines: Die Administration legt es an, als Gastzugang oder als Vollkonto.') }}
					</span>
				</section>

				<section v-if="mayManage" v-show="activeSection === 'projekt-archiv'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Projekt archivieren') }}
					</h3>
					<p v-if="store.projectArchived" class="pw-settings__hint pw-settings__hint--warn">
						{{ t('projektwerk', 'Dieses Projekt ist archiviert.') }}
					</p>
					<p class="pw-settings__hint">
						{{ t('projektwerk', 'Ein archiviertes Projekt bleibt mit allen Boards lesbar und verschwindet aus der Projektliste und dem Überblick.') }}
					</p>
					<div class="pw-viscontrol__actions">
						<NcButton v-if="store.projectArchived" :disabled="busy" @click="restoreProject">
							{{ t('projektwerk', 'Projekt aus dem Archiv holen') }}
						</NcButton>
						<NcButton v-else :disabled="busy" @click="archiveDialog = 'project'">
							{{ t('projektwerk', 'Projekt archivieren') }}
						</NcButton>
					</div>
				</section>

				<section v-if="mayManage && activeSection === 'protokoll'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Protokoll') }}
					</h3>
					<p class="pw-settings__hint">
						{{ t('projektwerk', 'Wer Mitglieder, Rollen, Verwaltungsrechte und Boards dieses Projekts geändert hat. Sichtbar nur für interne Mitglieder mit Verwaltungsrecht.') }}
					</p>
					<ProjectLogList :boardId="boardId" />
				</section>

				<section v-if="showBoardArchive" v-show="activeSection === 'board-archiv'" class="pw-settings__block">
					<h3 class="pw-col__head">
						{{ t('projektwerk', 'Board archivieren') }}
					</h3>
					<p v-if="store.board?.archived" class="pw-settings__hint pw-settings__hint--warn">
						{{ t('projektwerk', 'Dieses Board ist archiviert.') }}
					</p>
					<p class="pw-settings__hint">
						{{ t('projektwerk', 'Ein archiviertes Board bleibt lesbar und verschwindet aus den Reitern des Projekts.') }}
					</p>
					<div class="pw-viscontrol__actions">
						<NcButton v-if="store.board?.archived" :disabled="busy" @click="restoreBoard">
							{{ t('projektwerk', 'Board aus dem Archiv holen') }}
						</NcButton>
						<NcButton v-else :disabled="busy" @click="archiveDialog = 'board'">
							{{ t('projektwerk', 'Board archivieren') }}
						</NcButton>
					</div>
				</section>
			</div>
		</div>

		<!--
			Die Rueckfrage zum Entfernen einer Spalte. Ein Dialog und kein
			zweistufiger Bereich wie bei der Sichtbarkeit: Diese Seite ist kein
			Modal, hier legen sich also keine zwei Fokusfallen uebereinander.
		-->
		<NcDialog
			:open="removing !== null"
			:name="t('projektwerk', 'Spalte entfernen')"
			size="normal"
			@update:open="onRemoveDialogToggle">
			<!--
				Die App-Klasse MUSS hier drin stehen: NcDialog teleportiert
				seinen Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr
				mehr ist.
			-->
			<div v-if="removing !== null" class="app-projektwerk">
				<div class="pw-field">
					<label for="pw-del-target">{{ t('projektwerk', 'Wohin wandern die Vorgänge?') }}</label>
					<!--
						Ohne Vorbelegung: Wohin die Arbeit anderer wandert, ist
						eine Entscheidung. Eine geratene Antwort verschoebe sie
						an einen Ort, den niemand gewaehlt hat.
					-->
					<select id="pw-del-target" v-model="removeTarget">
						<option :value="null" disabled>
							{{ t('projektwerk', 'Zielspalte wählen') }}
						</option>
						<option v-for="column in removeTargets" :key="column.id" :value="column.id">
							{{ column.title }}
						</option>
					</select>
				</div>

				<p class="pw-settings__hint">
					{{ removeLead }}
				</p>
				<!--
					Die Zahl stammt aus der eigenen Ticketliste dieses Betrachters
					und weiss damit nie mehr als er. Der Satz danach sagt, dass
					sie nicht alles ist — sonst laese sie sich als Vollstaendigkeit.
				-->
				<p class="pw-settings__hint">
					{{ removeVisibleCount }}
				</p>
				<p class="pw-settings__hint">
					{{ t('projektwerk', 'Verborgene Vorgänge wandern mit, ohne hier aufzutauchen. Es geht nichts verloren.') }}
				</p>
			</div>

			<template #actions>
				<NcButton @click="cancelRemove">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy || removeTarget === null" @click="confirmRemove">
					{{ t('projektwerk', 'Verschieben und entfernen') }}
				</NcButton>
			</template>
		</NcDialog>

		<!--
			Mitglied entfernen (§5.29) — mit bezifferter Rückfrage. Die Zahl der
			privaten Vorgänge kommt vom Server (`removal-impact`); sie ist hier
			kein Leck, weil sie nur die Vorgänge **dieser** Person zählt, die
			ohnehin niemand sonst sieht.
		-->
		<NcDialog
			:open="removingMember !== null"
			:name="t('projektwerk', 'Mitglied entfernen')"
			size="normal"
			@update:open="onRemoveMemberToggle">
			<div v-if="removingMember !== null" class="app-projektwerk">
				<p class="pw-settings__hint">
					{{ removeMemberLead }}
				</p>
				<p v-if="removeMemberImpact === null" class="pw-settings__hint">
					{{ t('projektwerk', 'Wird geprüft…') }}
				</p>
				<p v-else-if="removeMemberImpact > 0" class="pw-settings__hint pw-settings__hint--warn">
					{{ removeMemberImpactText }}
				</p>
				<p v-else class="pw-settings__hint">
					{{ t('projektwerk', 'Keine privaten Vorgänge dieser Person werden gelöscht. Interne und öffentliche Vorgänge bleiben dem Projekt erhalten.') }}
				</p>
			</div>

			<template #actions>
				<NcButton @click="cancelRemoveMember">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy || removeMemberImpact === null" @click="confirmRemoveMember">
					{{ t('projektwerk', 'Entfernen') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			:open="archiveDialog !== null"
			:name="archiveDialog === 'project' ? t('projektwerk', 'Projekt archivieren') : t('projektwerk', 'Board archivieren')"
			size="normal"
			@update:open="onArchiveDialogToggle">
			<div v-if="archiveDialog !== null" class="app-projektwerk">
				<p class="pw-settings__hint">
					{{ archiveText }}
				</p>
			</div>

			<template #actions>
				<NcButton @click="archiveDialog = null">
					{{ t('projektwerk', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy" @click="confirmArchive">
					{{ t('projektwerk', 'Archivieren') }}
				</NcButton>
			</template>
		</NcDialog>

		<FolderPicker
			:open="picker.open"
			:startPath="picker.start"
			@update:open="picker.open = $event"
			@select="onPicked" />
	</div>
</template>

<script lang="ts">
import type { SettingsGroup } from '@/components/PwSettingsNav.vue'
import type { Candidate } from '@/services/settings'
import type { Column, Member, MemberRole, SiblingBoard } from '@/types/board'

import { n, t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import DeleteIcon from 'vue-material-design-icons/DeleteOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import FolderPicker from '@/components/FolderPicker.vue'
import ProjectLogList from '@/components/ProjectLogList.vue'
import PwSettingsNav from '@/components/PwSettingsNav.vue'
import { searchGithubRepos } from '@/services/github'
import {
	addMember,
	createColumn,
	deleteColumn,
	memberRemovalImpact,
	removeMember,
	renameColumn,
	reorderColumns,
	searchCandidates,
	setBoardArchived,
	setColumnFinalOutcome,
	setProjectArchived,
	updateBoard,
	updateMember,
} from '@/services/settings'
import { showError } from '@/services/toast'
import { useBoardStore } from '@/stores/boardStore'

/** Die Ordner der Dateiablage: Projektordner (#351) und die beiden Vorgangs-Ordner. */
type FolderSlot = 'root' | 'public' | 'internal'

/**
 * Der übergeordnete Ordner eines Pfades, oder leer.
 *
 * @param path Ein Pfad relativ zur Files-Wurzel.
 */
function parentPath(path: string): string {
	const parts = path.replace(/\/+$/, '').split('/')
	parts.pop()

	return parts.join('/')
}

/**
 * Projekt, Spalten, Mitglieder und Archiv pflegen.
 *
 * Die Dateiablage fehlt hier mit Absicht: Sie braucht den Ordner-Picker aus
 * `@nextcloud/dialogs`, und das Paket bricht den IIFE-Build (siehe
 * `src/types/globals.d.ts`). Sie gehört ohnehin zu den Anhängen ab Phase 7 —
 * ein Ordnerfeld ohne Anhänge wäre ein Versprechen ohne Einlösung.
 */
export default defineComponent({
	name: 'BoardSettingsView',

	components: { ArrowDownIcon, ArrowUpIcon, ChevronLeftIcon, DeleteIcon, FolderIcon, FolderPicker, LockIcon, NcAvatar, NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcSelect, NcTextField, ProjectLogList, PwSettingsNav },

	setup() {
		return { store: useBoardStore() }
	},

	data() {
		return {
			busy: false,
			newColumn: '',
			/** Entwurf je Spalte, solange getippt wird. */
			columnDrafts: {} as Record<number, string>,
			newMember: '',
			memberSearch: '',
			searching: false,
			candidates: [] as Candidate[],
			searchTimer: null as ReturnType<typeof setTimeout> | null,
			searchToken: 0,
			newMemberRole: 'external' as MemberRole,
			// Repo-Live-Suche (#196) — eigener Such-State, damit die
			// Mitglieder-Suche darüber unberührt bleibt.
			repoHits: [] as string[],
			repoSuchtLaeuft: false,
			/** Servermeldung, wenn die Suche scheitert (z. B. kein Token). */
			repoSuchFehler: '',
			repoSuchTimer: null as ReturnType<typeof setTimeout> | null,
			repoSuchToken: 0,
			/** Die Spalte, über deren Entfernen gerade zurückgefragt wird. */
			removing: null as Column | null,
			/** Pflichtangabe, deshalb ohne Vorbelegung. */
			removeTarget: null as number | null,
			/** Das Mitglied, über dessen Entfernen gerade zurückgefragt wird (§5.29). */
			removingMember: null as Member | null,
			/** Zahl der privaten Vorgänge, die das Entfernen löscht; null = wird noch geprüft. */
			removeMemberImpact: null as number | null,
			// Ein eigener Entwurf statt direkter Bindung an den Speicher: Sonst
			// stuenden Tippfehler sofort in der Kopfzeile des Boards, und ein
			// Abbruch waere nicht mehr moeglich.
			board: { projectTitle: '', projectDescription: '', title: '', description: '', orgInternal: '', customer: '', chatUrl: '', githubEnabled: false, githubRepo: '', memberBoardsAllowed: false },
			// Eigene Entwuerfe wie beim Board oben, aus demselben Grund: Der
			// Pfad muss erst geprueft werden, und bis dahin darf er nirgends
			// als der gespeicherte gelten.
			folderDrafts: { root: '', public: '', internal: '' } as Record<FolderSlot, string>,
			// Der Ordner-Wähler (#139) und für welchen der beiden Slots er offen ist.
			picker: { open: false, slot: 'public' as FolderSlot, start: '' },
			activeSection: '',
			/** Alle Boards des Projekts, archivierte eingeschlossen. */
			archiveDialog: null as 'board' | 'project' | null,
			/** Vor dem ersten Laden sähe jedes Projekt wie eines mit nur einem Board aus; beim Board-Wechsel bleibt die Seite stehen. */
			projectBoardsLoaded: false,
		}
	},

	computed: {
		boardId(): number {
			return Number(this.$route.params.boardId)
		},

		/** §8: Pflegen darf nur ein internes Mitglied mit Verwaltungsrecht. */
		mayManage(): boolean {
			return this.store.viewer?.isManager === true
		},

		/**
		 * Ob der Betrachter dieses Board **einrichten** darf (#281): Manager oder
		 * Ersteller. Der Ersteller sieht nur seinen board-scopeden Teil (Projekt-
		 * Titel, Spalten, Archiv); die Manager-Abschnitte bleiben ihm verborgen.
		 */
		mayConfigureBoard(): boolean {
			return this.mayManage || this.store.viewer?.isBoardCreator === true
		},

		projectBoards(): SiblingBoard[] {
			return this.store.siblings
		},

		/** Bei nur einem Board heißen Projekt und Board gleich; dann entfallen Auswahl, Boardname und Board-Archiv. */
		singleBoard(): boolean {
			return this.projectBoards.length <= 1
		},

		activeProjectBoards(): SiblingBoard[] {
			return this.projectBoards.filter((b) => !b.archived)
		},

		archivedProjectBoards(): SiblingBoard[] {
			return this.projectBoards.filter((b) => b.archived)
		},

		/** Archivierte stehen hinten und tragen den Zusatz, damit man sie zurückholen kann. */
		boardOptions(): { id: number, label: string }[] {
			return [
				...this.activeProjectBoards.map((b) => ({ id: b.id, label: b.title })),
				...this.archivedProjectBoards.map((b) => ({ id: b.id, label: t('projektwerk', '{title} (archiviert)', { title: b.title }, undefined, { escape: false }) })),
			]
		},

		selectedBoardOption(): { id: number, label: string } | null {
			return this.boardOptions.find((o) => o.id === this.boardId) ?? null
		},

		showBoardPicker(): boolean {
			return this.mayManage && !this.singleBoard
		},

		/** Ein einzeln archiviertes Einzelboard (Altbestand) muss zurückholbar bleiben. */
		showBoardArchive(): boolean {
			return !this.singleBoard || this.store.board?.archived === true
		},

		navGroups(): SettingsGroup[] {
			const boardItems = [
				...(this.singleBoard ? [] : [{ key: 'board', label: t('projektwerk', 'Allgemein') }]),
				{ key: 'spalten', label: t('projektwerk', 'Spalten') },
				...(this.mayManage ? [{ key: 'github', label: 'GitHub' }] : []),
				...(this.showBoardArchive ? [{ key: 'board-archiv', label: t('projektwerk', 'Archiv') }] : []),
			]
			const board = { key: 'board', label: t('projektwerk', 'Board'), items: boardItems }
			if (!this.mayManage) {
				return [board]
			}

			return [
				{
					key: 'projekt',
					label: t('projektwerk', 'Projekt'),
					items: [
						{ key: 'projekt', label: t('projektwerk', 'Allgemein') },
						{ key: 'mitglieder', label: t('projektwerk', 'Mitglieder') },
						{ key: 'dateiablage', label: t('projektwerk', 'Dateiablage') },
						{ key: 'projekt-archiv', label: t('projektwerk', 'Archiv') },
						{ key: 'protokoll', label: t('projektwerk', 'Protokoll') },
					],
				},
				board,
			]
		},

		visibleSections(): string[] {
			return this.navGroups.flatMap((g) => g.items.map((i) => i.key))
		},

		ausUebersicht(): boolean {
			return this.$route.query.zurueck === 'uebersicht'
		},

		crumbLabel(): string {
			if (this.ausUebersicht) {
				return t('projektwerk', 'Übersicht')
			}
			return t('projektwerk', 'Board „{title}“', { title: this.store.board?.title ?? '' }, undefined, { escape: false })
		},

		projectLine(): string {
			if (this.store.projectTitle === '') {
				return ''
			}
			const projekt = t('projektwerk', 'Projekt „{title}“', { title: this.store.projectTitle }, undefined, { escape: false })
			const kunde = this.store.board?.customer

			return kunde ? `${projekt} · ${kunde}` : projekt
		},

		archiveText(): string {
			if (this.archiveDialog === 'project') {
				return t('projektwerk', 'Das Projekt „{title}“ wird mit allen Boards archiviert. Es verschwindet aus der Projektliste und dem Überblick. Nichts wird gelöscht; zurückholen über Projekt › Archiv.', { title: this.store.projectTitle }, undefined, { escape: false })
			}

			return t('projektwerk', 'Nur das Board „{title}“ wird archiviert. Die anderen Boards des Projekts bleiben unverändert. Nichts wird gelöscht; zurückholen über die Board-Auswahl.', { title: this.store.board?.title ?? '' }, undefined, { escape: false })
		},

		/**
		 * Die beiden Ablageorte als Zeilen, damit die Vorlage sie nicht doppelt
		 * ausschreibt.
		 *
		 * Die Reihenfolge ist nicht beliebig: „Austausch" steht oben, weil er
		 * der Ordner ist, den **beide** Seiten sehen — und weil ein Projekt ohne
		 * internen Ordner arbeitsfähig ist, eines ohne Austauschordner aber
		 * seinen Zweck verfehlt.
		 */
		folderSlots(): { key: FolderSlot, label: string, hint: string, placeholder: string, path: string }[] {
			const board = this.store.board

			return [
				{
					key: 'root',
					label: t('projektwerk', 'Projektordner'),
					hint: t('projektwerk', 'Der Oberordner des Projekts. Der Knopf „Projektordner" im Projektkopf öffnet ihn, und die Ordnerauswahl für die beiden Ordner darunter beginnt hier.'),
					placeholder: 'Projekte/Kunde A',
					path: this.store.projectFolder?.path ?? '',
				},
				{
					key: 'public',
					label: t('projektwerk', 'Ordner für Vorgänge, die alle Beteiligten sehen'),
					hint: t('projektwerk', 'Die Kundenseite hat Zugriff. Leer lassen heißt: an diesen Vorgängen sind keine Anhänge möglich.'),
					placeholder: 'Projekte/Kunde A/90_Austausch',
					path: board?.folderPublicPath ?? '',
				},
				{
					key: 'internal',
					label: t('projektwerk', 'Ordner für interne Vorgänge'),
					hint: t('projektwerk', 'Nur die eigene Seite hat Zugriff. Leer lassen heißt: an internen Vorgängen sind keine Anhänge möglich.'),
					placeholder: 'Projekte/Kunde A/91_Tickets_intern',
					path: board?.folderInternalPath ?? '',
				},
			]
		},

		/**
		 * Spalten entfernen darf **nur der Eigentümer** — nicht jeder mit
		 * Verwaltungsrecht (#60).
		 *
		 * Der Server weist jeden anderen mit 403 ab; hier fällt nur der Knopf
		 * weg, damit niemand eine Zielspalte wählt, die er nicht abschicken
		 * darf.
		 */
		mayRemoveColumns(): boolean {
			// Löschen bleibt dem Projekt-Owner vorbehalten — auch der Board-Ersteller
			// (#281) darf Spalten anlegen/umbenennen/ordnen, aber nicht entfernen
			// (destruktiv, fasst evtl. unsichtbare Tickets an).
			const owner = this.store.board?.ownerUserId
			return owner !== undefined && owner === this.store.viewer?.userId
		},

		/** Jede Spalte außer der, die wegfällt. */
		removeTargets(): Column[] {
			return this.store.columns.filter((column) => column.id !== this.removing?.id)
		},

		/**
		 * Was passiert, in einem Satz — als Verschiebung, nicht als Verlust.
		 *
		 * Steht im Skript und nicht in der Vorlage, weil der Text
		 * Anführungszeichen trägt; im Attribut beendeten sie die Zeichenkette.
		 */
		removeLead(): string {
			const target = this.store.columns.find((column) => column.id === this.removeTarget)

			if (this.removing === null || target === undefined) {
				return t('projektwerk', 'Alle Vorgänge wandern in die gewählte Spalte, danach fällt „{from}“ weg.', {
					from: this.removing?.title ?? '',
				})
			}

			return t('projektwerk', 'Alle Vorgänge wandern nach „{to}“, danach fällt „{from}“ weg.', {
				from: this.removing.title,
				to: target.title,
			})
		},

		/**
		 * Wie viele Vorgänge **dieser Betrachter** in der Spalte sieht.
		 *
		 * Aus der bereits geladenen Ticketliste, nicht aus einer eigenen
		 * Abfrage: Ein Zähler-Endpunkt wäre ein zweiter Ort, an dem die
		 * Sichtbarkeitsregel stimmen müsste, und die Zahl darf ohnehin nie mehr
		 * wissen als der Betrachter. Der Filter „Nur wartend" bleibt hier außen
		 * vor — deshalb `columnOrder` und nicht `ticketsIn()`.
		 */
		removeVisibleCount(): string {
			const anzahl = this.removing === null
				? 0
				: (this.store.columnOrder.get(this.removing.id)?.length ?? 0)

			return n(
				'projektwerk',
				'Für dich sichtbar ist davon %n Vorgang.',
				'Für dich sichtbar sind davon %n Vorgänge.',
				anzahl,
			)
		},

		/** Der einleitende Satz der Mitglied-Entfernen-Rückfrage. */
		removeMemberLead(): string {
			const name = this.removingMember?.resolvedName ?? ''
			return t('projektwerk', '{name} aus dem Projekt entfernen? Offene Zuweisungen dieser Person werden aufgehoben.', { name })
		},

		/** Die bezifferte Warnung über die zu löschenden privaten Vorgänge. */
		removeMemberImpactText(): string {
			return n(
				'projektwerk',
				'Dabei wird %n privater Vorgang dieser Person unwiederbringlich gelöscht.',
				'Dabei werden %n private Vorgänge dieser Person unwiederbringlich gelöscht.',
				this.removeMemberImpact ?? 0,
			)
		},
	},

	watch: {
		boardId: {
			immediate: true,
			async handler(id: number) {
				await this.store.open(id)
				this.fillDraft()
				this.projectBoardsLoaded = true
				this.pickSection()
			},
		},

		'$route.query.bereich': function() {
			if (this.projectBoardsLoaded) {
				this.pickSection()
			}
		},

		visibleSections() {
			if (this.projectBoardsLoaded) {
				this.pickSection()
			}
		},
	},

	methods: {
		t,

		/**
		 * @param nur Nur diese Felder neu füllen, damit ungespeicherte Eingaben anderer Bereiche bleiben; `ordner` steht für die Dateiablage.
		 */
		fillDraft(nur?: string[]) {
			const board = this.store.board
			if (board === null) {
				return
			}
			const frisch = {
				projectTitle: this.store.projectTitle,
				projectDescription: this.store.projectDescription ?? '',
				title: board.title,
				description: board.description ?? '',
				orgInternal: board.orgInternal ?? '',
				customer: board.customer ?? '',
				chatUrl: board.chatUrl ?? '',
				githubEnabled: board.githubEnabled,
				githubRepo: board.githubRepo ?? '',
				// #281: projektweiter Schalter, kommt aus dem Store (nicht am Board).
				memberBoardsAllowed: this.store.memberBoardsAllowed,
			}
			const ordner = {
				root: this.store.projectFolder?.path ?? '',
				public: board.folderPublicPath ?? '',
				internal: board.folderInternalPath ?? '',
			}
			if (nur === undefined) {
				this.board = frisch
				this.folderDrafts = ordner
				return
			}
			for (const feld of nur) {
				if (feld === 'ordner') {
					this.folderDrafts = ordner
				} else if (feld in frisch) {
					(this.board as Record<string, unknown>)[feld] = (frisch as Record<string, unknown>)[feld]
				}
			}
		},

		/** Den Bereich aus der Adresse übernehmen, sonst den ersten, den der Betrachter sehen darf. */
		pickSection(): void {
			const gewuenscht = String(this.$route.query.bereich ?? this.activeSection)
			if (this.visibleSections.includes(gewuenscht)) {
				this.activeSection = gewuenscht
			} else if (!this.visibleSections.includes(this.activeSection)) {
				this.activeSection = this.visibleSections[0] ?? ''
			}
		},

		/**
		 * @param key Der gewählte Bereich.
		 */
		selectSection(key: string): void {
			this.activeSection = key
			this.$router.replace({ query: { ...this.$route.query, bereich: key } })
		},

		/**
		 * @param option Die gewählte Option der Board-Auswahl.
		 */
		switchSettingsBoard(option: { id: number } | null): void {
			if (option === null || option.id === this.boardId) {
				return
			}
			this.$router.replace({ name: 'board-settings', params: { boardId: String(option.id) }, query: { ...this.$route.query, bereich: this.activeSection } })
		},

		/** Boardliste und Reiter nachziehen, wenn sich Namen oder Archiv geändert haben. */
		async refreshLists(): Promise<void> {
			await this.store.loadBoards()
		},

		/**
		 * @param member Die Mitgliedschaft.
		 */
		isOwner(member: Member): boolean {
			return this.store.board?.ownerUserId === member.userId
		},

		/**
		 * Die Kennung als lesbare Zweitzeile — oder leer.
		 *
		 * Ein Gast-Konto trägt als Kennung einen 64-stelligen Hex-Hash (Gast-UIDs
		 * sind genau so gebaut). Der gehört nicht in die Oberfläche: Er sagt
		 * niemandem etwas und stünde als Zeichensalat unter dem Namen. In dem
		 * Fall bleibt die Zeile leer, der aufgelöste Name oben trägt sie.
		 *
		 * @param member Die Mitgliedschaft.
		 */
		handleFor(member: Member): string {
			const uid = member.userId
			return /^[a-f0-9]{64}$/i.test(uid) ? '' : uid
		},

		/**
		 * Einen Schreibvorgang fahren und danach neu laden.
		 *
		 * Neu laden statt lokal nachzuziehen: Rolle und Verwaltungsrecht
		 * korrigiert der Server nach §8 gegebenenfalls selbst — ein extern
		 * gesetztes Verwaltungsrecht kommt als `false` zurück. Eine lokale
		 * Vorwegnahme zeigte einen Zustand, den es auf dem Server nie gab.
		 *
		 * @param run Der Aufruf.
		 * @param fallback Meldung, wenn der Server keine eigene mitgibt.
		 * @param lists Danach auch Boardliste und Reiter neu laden.
		 * @param felder Die Entwurfsfelder, die der Aufruf gespeichert hat; nur sie kommen frisch vom Server.
		 */
		async write(run: () => Promise<unknown>, fallback: string, lists = false, felder: string[] = []): Promise<void> {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await run()
				if (lists) {
					await this.refreshLists()
				}
				await this.store.open(this.boardId)
				this.fillDraft(felder)
				// Die Entwuerfe sind mit dem Neuladen ueberholt; blieben sie
				// stehen, ueberdeckten sie den Stand vom Server.
				this.columnDrafts = {}
			} catch (e) {
				showError((e as { message?: string }).message ?? fallback)
			} finally {
				this.busy = false
			}
		},

		// Stellt ein Inline-Feld zurück, wenn der Server die Änderung nicht übernommen hat.
		async writeInline(run: () => Promise<unknown>, fallback: string, restore: () => void): Promise<void> {
			let saved = false
			await this.write(async () => {
				await run()
				saved = true
			}, fallback)
			if (!saved) {
				// Vue setzt das Feld nicht zurück, weil sich der gebundene Wert nicht geändert hat.
				restore()
			}
		},

		saveProject() {
			return this.write(
				() => updateBoard(this.boardId, {
					projectTitle: this.board.projectTitle.trim(),
					projectDescription: this.blankToNull(this.board.projectDescription),
					orgInternal: this.blankToNull(this.board.orgInternal),
					customer: this.blankToNull(this.board.customer),
					chatUrl: this.blankToNull(this.board.chatUrl),
					memberBoardsAllowed: this.board.memberBoardsAllowed,
				}),
				t('projektwerk', 'Speichern fehlgeschlagen'),
				true,
				['projectTitle', 'projectDescription', 'orgInternal', 'customer', 'chatUrl', 'memberBoardsAllowed', ...(this.singleBoard ? ['title', 'description'] : [])],
			)
		},

		saveBoardGeneral() {
			return this.write(
				() => updateBoard(this.boardId, {
					title: this.board.title.trim(),
					description: this.blankToNull(this.board.description),
				}),
				t('projektwerk', 'Speichern fehlgeschlagen'),
				true,
				['title', 'description'],
			)
		},

		saveGithub() {
			return this.write(
				() => updateBoard(this.boardId, {
					githubEnabled: this.board.githubEnabled,
					// Roher String: Ein leeres Repo entfernt das Ziel, null hieße „nicht geschickt“.
					githubRepo: this.board.githubRepo.trim(),
				}),
				t('projektwerk', 'Speichern fehlgeschlagen'),
				false,
				['githubEnabled', 'githubRepo'],
			)
		},

		/**
		 * Einen Projektordner über seinen Pfad festlegen — oder leeren.
		 *
		 * **Der Pfad geht hin, die ID bleibt hier.** Der Server löst auf und
		 * speichert die Datei-ID; zurück kommt der kanonische Pfad, und
		 * `fillDraft` schreibt ihn ins Feld. Wer sich vertippt hat, sieht das
		 * daran, dass die Meldung des Servers kommt und das Feld unverändert
		 * bleibt.
		 *
		 * Ein leeres Feld entfernt die Zuordnung. Der Ordner selbst bleibt
		 * unangetastet — die App löscht nicht (§5.18); danach sind an den
		 * betroffenen Vorgängen nur keine Anhänge mehr möglich.
		 *
		 * @param slot Welcher der beiden Ordner.
		 */
		saveFolder(slot: FolderSlot): Promise<void> {
			const path = this.folderDrafts[slot].trim()
			const field = { root: 'folderRootPath', public: 'folderPublicPath', internal: 'folderInternalPath' }[slot]

			return this.write(
				() => updateBoard(this.boardId, { [field]: path }),
				t('projektwerk', 'Ordner konnte nicht gespeichert werden'),
				false,
				['ordner'],
			)
		},

		/**
		 * Den Ordner-Wähler für einen Slot öffnen — beim bereits gesetzten
		 * Ordner beginnend, damit man nicht jedes Mal von der Wurzel klickt.
		 * Ist er leer, beginnt ein Vorgangs-Ordner im Projektordner (#351), und
		 * der Projektordner eine Ebene über einem schon gesetzten Vorgangs-Ordner.
		 *
		 * @param slot Welcher Ordner.
		 */
		openPicker(slot: FolderSlot): void {
			const own = this.folderDrafts[slot].trim()
			const fallback = slot === 'root'
				? parentPath(this.folderDrafts.public.trim() || this.folderDrafts.internal.trim())
				: this.folderDrafts.root.trim()
			this.picker = { open: true, slot, start: own || fallback }
		},

		/**
		 * Die Wahl aus dem Picker übernehmen und gleich speichern — die Auswahl
		 * eines Ordners ist bereits die Bestätigung.
		 *
		 * @param path Der gewählte Pfad relativ zur Files-Wurzel.
		 */
		onPicked(path: string): void {
			this.folderDrafts[this.picker.slot] = path
			this.saveFolder(this.picker.slot)
		},

		addColumn() {
			const title = this.newColumn.trim()
			if (title === '') {
				return
			}
			return this.write(
				async () => {
					await createColumn(this.boardId, title)
					this.newColumn = ''
				},
				t('projektwerk', 'Spalte konnte nicht angelegt werden'),
			)
		},

		/**
		 * Der Text im Feld: der Entwurf, sonst der gespeicherte Name.
		 *
		 * @param column Die Spalte.
		 */
		draftFor(column: Column): string {
			return this.columnDrafts[column.id] ?? column.title
		},

		/**
		 * Den Entwurf speichern — bei Enter oder beim Verlassen des Feldes.
		 *
		 * Ein leerer Name wird verworfen statt geschickt: Der Server wiese ihn
		 * ab, und die Meldung waere die Antwort auf ein Versehen. Das Feld
		 * kehrt dann zum gespeicherten Namen zurueck.
		 *
		 * @param column Die Spalte.
		 */
		commitColumn(column: Column) {
			const draft = this.columnDrafts[column.id]
			if (draft === undefined) {
				return
			}

			const title = draft.trim()
			if (title === '' || title === column.title) {
				delete this.columnDrafts[column.id]

				return
			}

			return this.write(
				() => renameColumn(this.boardId, column.id, title),
				t('projektwerk', 'Umbenennen fehlgeschlagen'),
			)
		},

		/**
		 * Die Spalte als Endspalte markieren (#172).
		 *
		 * @param column Die Spalte.
		 * @param event Das change-Ereignis des Auswahlfelds.
		 */
		setColumnOutcome(column: Column, event: Event) {
			const select = event.target as HTMLSelectElement
			const outcome = select.value === '' ? null : (select.value as 'done' | 'discarded')

			return this.writeInline(
				() => setColumnFinalOutcome(this.boardId, column.id, outcome),
				t('projektwerk', 'Endspalte konnte nicht gesetzt werden'),
				() => { select.value = column.finalOutcome ?? '' },
			)
		},

		/**
		 * Die Beschriftung des Löschknopfs — mit Namen, nicht ohne.
		 *
		 * @param column Die Spalte.
		 */
		removeLabelFor(column: Column): string {
			return t('projektwerk', 'Spalte „{title}“ entfernen', { title: column.title })
		},

		/**
		 * Die Rückfrage öffnen.
		 *
		 * @param column Die Spalte, die wegfallen soll.
		 */
		askRemoveColumn(column: Column) {
			this.removing = column
			this.removeTarget = null
		},

		cancelRemove() {
			this.removing = null
			this.removeTarget = null
		},

		/**
		 * Nur das Schließen zurücknehmen, nicht jede Meldung.
		 *
		 * `NcDialog` meldet beide Richtungen über dasselbe Ereignis. Würde hier
		 * blind geschlossen, verschwände der Dialog im selben Zug, in dem er
		 * aufgeht.
		 *
		 * @param open Der neue Zustand.
		 */
		onRemoveDialogToggle(open: boolean) {
			if (!open) {
				this.cancelRemove()
			}
		},

		/**
		 * Entfernen heißt verschieben: erst wandern die Vorgänge, dann fällt
		 * die Spalte weg — beides in einer Transaktion auf dem Server.
		 */
		confirmRemove() {
			const column = this.removing
			const target = this.removeTarget
			if (column === null || target === null) {
				return
			}

			return this.write(
				async () => {
					await deleteColumn(this.boardId, column.id, target)
					this.cancelRemove()
				},
				t('projektwerk', 'Spalte konnte nicht entfernt werden'),
			)
		},

		/**
		 * Die Rückfrage zum Entfernen eines Mitglieds öffnen und die Zahl der
		 * betroffenen privaten Vorgänge vom Server holen (§5.29).
		 *
		 * @param member Das Mitglied.
		 */
		async askRemoveMember(member: Member) {
			this.removingMember = member
			this.removeMemberImpact = null
			try {
				const impact = await memberRemovalImpact(this.boardId, member.userId)
				// Nur übernehmen, wenn der Dialog noch demselben Mitglied gilt —
				// ein schneller Wechsel darf keine fremde Zahl einblenden.
				if (this.removingMember?.userId === member.userId) {
					this.removeMemberImpact = impact.privateTickets
				}
			} catch (e) {
				showError((e as { message?: string }).message ?? t('projektwerk', 'Die Vorschau konnte nicht geladen werden'))
				this.cancelRemoveMember()
			}
		},

		cancelRemoveMember() {
			this.removingMember = null
			this.removeMemberImpact = null
		},

		/**
		 * @param open Der neue Zustand des Dialogs.
		 */
		onRemoveMemberToggle(open: boolean) {
			if (!open) {
				this.cancelRemoveMember()
			}
		},

		/**
		 * Das Mitglied entfernen — private Vorgänge löschen, Zuweisungen lösen,
		 * Mitgliedschaft entfernen, alles serverseitig in einer Transaktion.
		 */
		confirmRemoveMember() {
			const member = this.removingMember
			if (member === null) {
				return
			}

			return this.write(
				async () => {
					await removeMember(this.boardId, member.userId)
					this.cancelRemoveMember()
				},
				t('projektwerk', 'Mitglied konnte nicht entfernt werden'),
			)
		},

		/**
		 * Eine Spalte um einen Platz verschieben.
		 *
		 * Der Server verlangt die **vollständige** Reihenfolge und weist eine
		 * unvollständige ab — sonst entschiede über die nicht genannten Spalten
		 * der Zufall. Deshalb wird hier die ganze Liste getauscht und geschickt.
		 *
		 * @param index Aktuelle Position.
		 * @param delta -1 nach oben, +1 nach unten.
		 */
		moveColumn(index: number, delta: number) {
			const ids = this.store.columns.map((c) => c.id)
			const target = index + delta
			if (target < 0 || target >= ids.length) {
				return
			}
			const moved = ids[index]
			ids[index] = ids[target]
			ids[target] = moved

			return this.write(
				() => reorderColumns(this.boardId, ids),
				t('projektwerk', 'Reihenfolge konnte nicht gespeichert werden'),
			)
		},

		/**
		 * Konten suchen — gedrosselt, nicht bei jedem Tastendruck.
		 *
		 * Eine Suche je Zeichen hiesse ein Aufruf pro Anschlag. Das Zeitfenster
		 * allein reicht aber nicht: Zwei tatsaechlich abgeschickte Anfragen
		 * koennen im Netz die Reihenfolge tauschen. Ein Zaehler je Anfrage
		 * verwirft deshalb jede Antwort, die nicht mehr zur letzten gehoert.
		 */
		searchPeople() {
			if (this.searchTimer !== null) {
				clearTimeout(this.searchTimer)
			}
			this.newMember = ''
			this.searchToken += 1

			const begriff = this.memberSearch.trim()
			if (begriff === '') {
				this.candidates = []
				this.searching = false

				return
			}

			this.searching = true
			const token = this.searchToken
			this.searchTimer = setTimeout(async () => {
				try {
					const treffer = await searchCandidates(this.boardId, begriff)
					if (token === this.searchToken) {
						this.candidates = treffer
					}
				} catch (e) {
					if (token === this.searchToken) {
						// Ohne Verwaltungsrecht antwortet der Server mit 403 statt
						// mit einer leeren Liste — die Meldung sagt dann, woran es
						// liegt, statt „niemand gefunden" vorzutäuschen.
						this.candidates = []
						showError((e as { message?: string }).message ?? t('projektwerk', 'Suche fehlgeschlagen'))
					}
				} finally {
					if (token === this.searchToken) {
						this.searching = false
					}
				}
			}, 300)
		},

		/**
		 * Repos suchen, während getippt wird (#196) — gedrosselt und rennfest,
		 * wie die Personensuche darüber. Ein **leerer** Feldinhalt liefert die
		 * ersten Repos (Dropdown-Gefühl), statt die Liste zu leeren.
		 */
		repoSuchen() {
			this.repoFetch(this.board.githubRepo.trim(), false)
		},

		/**
		 * Beim Reinklicken/Fokussieren gleich die ersten Repos zeigen (#196) —
		 * nur wenn noch keine Treffer stehen, damit ein zweiter Klick nichts
		 * neu lädt. Sofort, ohne die Tipp-Drosselung.
		 */
		repoFokus() {
			// Leerer Begriff = die ersten Repos zum Browsen; Tippen filtert
			// danach. Sonst würde ein bereits eingetragener voller Name sofort
			// „keine Treffer" zeigen.
			if (this.repoHits.length === 0 && !this.repoSuchtLaeuft) {
				this.repoFetch('', true)
			}
		},

		/**
		 * Die eigentliche Suche. Scheitert sie (kein Token, GitHub-Fehler),
		 * bleibt das Feld als Freitext bedienbar und die Meldung sagt, woran es
		 * liegt.
		 *
		 * @param begriff Suchbegriff; leer liefert die ersten Repos.
		 * @param sofort Ohne Drosselung laden (beim Fokus), sonst mit 300 ms.
		 */
		repoFetch(begriff: string, sofort: boolean) {
			if (this.repoSuchTimer !== null) {
				clearTimeout(this.repoSuchTimer)
			}
			this.repoSuchFehler = ''
			this.repoSuchToken += 1
			this.repoSuchtLaeuft = true
			const token = this.repoSuchToken

			const laden = async () => {
				try {
					const { repos } = await searchGithubRepos(begriff)
					if (token === this.repoSuchToken) {
						// Den exakt schon eingetragenen Namen nicht noch einmal als
						// Vorschlag anbieten.
						this.repoHits = repos.filter((r) => r !== this.board.githubRepo)
					}
				} catch (e) {
					if (token === this.repoSuchToken) {
						this.repoHits = []
						this.repoSuchFehler = (e as { message?: string }).message
							?? t('projektwerk', 'Repositorys konnten nicht geladen werden')
					}
				} finally {
					if (token === this.repoSuchToken) {
						this.repoSuchtLaeuft = false
					}
				}
			}

			if (sofort) {
				laden()
			} else {
				this.repoSuchTimer = setTimeout(laden, 300)
			}
		},

		/**
		 * @param repo Der gewählte Repo-Name „owner/repo".
		 */
		repoWaehlen(repo: string) {
			this.board.githubRepo = repo
			this.repoHits = []
			this.repoSuchFehler = ''
		},

		addMemberToBoard() {
			const userId = this.newMember
			if (userId === '') {
				return
			}
			// Firma vorbelegen (#309): intern = eigene Firma, extern = Kunde des
			// Projekts. Danach in der Zeile überschreibbar (z. B. Subunternehmer).
			const company = this.newMemberRole === 'external'
				? this.blankToNull(this.board.customer)
				: this.blankToNull(this.board.orgInternal)

			return this.write(
				async () => {
					await addMember(this.boardId, { userId, role: this.newMemberRole, company })
					this.newMember = ''
					this.memberSearch = ''
					this.candidates = []
				},
				t('projektwerk', 'Mitglied konnte nicht hinzugefügt werden'),
			)
		},

		/**
		 * @param member Die Mitgliedschaft.
		 * @param event Das Auswahlereignis.
		 */
		changeRole(member: Member, event: Event) {
			const select = event.target as HTMLSelectElement
			const role = select.value as MemberRole

			return this.writeInline(
				() => updateMember(this.boardId, member.userId, { role }),
				t('projektwerk', 'Rolle konnte nicht geändert werden'),
				() => { select.value = member.role },
			)
		},

		/**
		 * @param member Die Mitgliedschaft.
		 * @param event Das Umschaltereignis.
		 */
		changeManager(member: Member, event: Event) {
			const box = event.target as HTMLInputElement
			const isManager = box.checked

			return this.writeInline(
				() => updateMember(this.boardId, member.userId, { isManager }),
				t('projektwerk', 'Verwaltungsrecht konnte nicht geändert werden'),
				() => { box.checked = Boolean(member.isManager) },
			)
		},

		/**
		 * Firma eines Mitglieds ändern (#309) — leer entfernt sie.
		 *
		 * @param member Die Mitgliedschaft.
		 * @param event Das Änderungsereignis des Firmenfelds.
		 */
		changeCompany(member: Member, event: Event) {
			const company = this.blankToNull((event.target as HTMLInputElement).value)

			return this.write(
				() => updateMember(this.boardId, member.userId, { company }),
				t('projektwerk', 'Firma konnte nicht geändert werden'),
			)
		},

		/**
		 * @param open Der neue Zustand des Dialogs.
		 */
		onArchiveDialogToggle(open: boolean) {
			if (!open) {
				this.archiveDialog = null
			}
		},

		confirmArchive() {
			const project = this.archiveDialog === 'project'

			return this.write(
				async () => {
					await (project ? setProjectArchived(this.boardId, true) : setBoardArchived(this.boardId, true))
					this.archiveDialog = null
				},
				t('projektwerk', 'Archivieren fehlgeschlagen'),
				true,
			)
		},

		restoreBoard() {
			return this.write(
				() => setBoardArchived(this.boardId, false),
				t('projektwerk', 'Zurückholen fehlgeschlagen'),
				true,
			)
		},

		restoreProject() {
			return this.write(
				() => setProjectArchived(this.boardId, false),
				t('projektwerk', 'Zurückholen fehlgeschlagen'),
				true,
			)
		},

		/**
		 * @param value Der eingegebene Text.
		 */
		blankToNull(value: string): string | null {
			const trimmed = value.trim()
			return trimmed === '' ? null : trimmed
		},
	},
})
</script>
