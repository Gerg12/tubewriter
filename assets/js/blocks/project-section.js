const { registerBlockType } = wp.blocks;
const {
	RichText,
	MediaUpload,
} = wp.editor;
const {
	Button,
	TextControl,
} = wp.components;
const { withSelect } = wp.data;

// Project Section Block
registerBlockType("custom-block-plugin/project-section", {
	title: "Project Section",
	icon: "portfolio",
	category: "common",
	attributes: {
		imageUrl: {
			type: "string",
			default: "",
		},
		imageAlt: {
			type: "string",
			default: "",
		},
		blockText: {
			type: "string",
			default: "",
		},
		blockTitle: {
			type: "string",
			default: "",
		},
		allProjectsLink: {
			type: "string",
			default: "",
		},
		projects: {
			type: "array",
			default: [],
		},
	},
	edit: withSelect((select) => {
		const { getEntityRecords } = select("core");

		// Fetch categories and projects
		const categories = getEntityRecords("taxonomy", "category", {
			slug: "featured",
		});

		let projects = [];
		if (categories && categories.length > 0) {
			const featuredCategoryId = categories[0].id;
			projects = getEntityRecords("postType", "project", {
				per_page: 5,
				categories: featuredCategoryId,
			});
		}

		return {
			categories,
			projects: projects || [],
		};
	})(({ attributes, setAttributes, projects }) => {
		const { imageUrl, imageAlt, blockText, blockTitle, allProjectsLink } =
			attributes;

		const onSelectImage = (media) => {
			setAttributes({
				imageUrl: media.url,
				imageAlt: media.alt,
			});
		};

		const onBlockTextChange = (value) => {
			setAttributes({ blockText: value });
		};

		const onBlockTitleChange = (value) => {
			setAttributes({ blockTitle: value });
		};

		const onAllProjectsLinkChange = (value) => {
			setAttributes({ allProjectsLink: value });
		};

		// Update projects attribute in block's attributes
		if (projects && projects.length > 0) {
			setAttributes({ projects });
		}


		if (!projects) {
			return <p>Loading projects...</p>;
		}

		return (
			<div className="project-section__wrapper">
				<div className="project-section__inner">
					<div className="project-section__left-column">
						<MediaUpload
								onSelect={onSelectImage}
								type="image"
								value={imageUrl}
								render={({ open }) => (
									<Button onClick={open}>Select Image</Button>
								)}
							/>
							{imageUrl && (
								<img
									src={imageUrl}
									alt="Project Image"
									style={{ maxWidth: "100%" }}
								/>
							)}
						
						<RichText
							className="project-section__text"
							tagName="p"
							value={blockText}
							onChange={onBlockTextChange}
							placeholder="Enter block text"
						/>
					</div>
					<div className="project-section__right-column">
						<RichText
							className="project-section__block-title"
							tagName="h2"
							value={blockTitle}
							onChange={onBlockTitleChange}
							placeholder="Enter block title"
						/>
						<ul className="project-section__list">
							{projects && projects.length > 0 ? (
								projects.map((project) => (
									<li key={project.id} className="project-section__item">
										<h3 className="project-section__item-title">
											{project.title.rendered}
										</h3>
										<div className="project-section__item-row">
											<div
												className="project-section__item-subtitle"
												dangerouslySetInnerHTML={{
													__html: project.excerpt.rendered,
												}}
											/>
											<a href={project.link} className="project-section__item-link">
												Visit Project
											</a>
										</div>
									</li>
								))
							) : (
								<li>No projects found.</li>
							)}
						</ul>
						<TextControl
							className="project-section__all-projects-link"
							value={allProjectsLink}
							onChange={onAllProjectsLinkChange}
							placeholder="Enter 'See All Projects' link"
						/>
						<a
							href={allProjectsLink}
							className="project-section__all-projects-link-text"
						>
							See All&nbsp;Projects
						</a>
					</div>
				</div>
			</div>
		);
	}),
	save: ({ attributes }) => {
		const {
			imageUrl,
			imageAlt,
			blockText,
			blockTitle,
			allProjectsLink,
			projects, // Ensure projects are directly accessible here
		} = attributes;

		console.log("Attributes in save:", attributes);

		return (
			<div className="project-section__wrapper">
				<div className="project-section__inner">
					<div className="project-section__left-column">
						{imageUrl && (
							<img
								src={imageUrl}
								alt={imageAlt}
								className="project-section__image"
							/>
						)}
						<RichText.Content
							className="project-section__text"
							tagName="p"
							value={blockText}
						/>
					</div>
					<div className="project-section__right-column">
						<RichText.Content
							className="project-section__block-title"
							tagName="h2"
							value={blockTitle}
						/>
						<ul className="project-section__list">
							{/* Ensure projects are correctly retrieved */}
							{projects && projects.length > 0 ? (
								projects.map((project) => (
									<li key={project.id} className="project-section__item">
										<h3 className="project-section__item-title">
											{project.title.rendered}
										</h3>
										<div className="project-section__item-row">
											<div
												className="project-section__item-subtitle"
												dangerouslySetInnerHTML={{
													__html: project.excerpt.rendered,
												}}
											/>
											<a href={project.link} className="project-section__item-link">
												Visit Project
											</a>
										</div>
									</li>
								))
							) : (
								<li>No projects found.</li>
							)}
						</ul>
						<a
							href={allProjectsLink}
							className="project-section__all-projects-link-text"
						>
							See All&nbsp;Projects
						</a>
					</div>
				</div>
			</div>
		);
	},
});